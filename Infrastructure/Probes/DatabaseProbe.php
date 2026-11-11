<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Probes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\ProbeResult;

/**
 * Can the application reach its database and get an answer back?
 *
 * `SELECT 1` and nothing more. It is the cheapest statement that proves the
 * whole path — connection, authentication, the driver, the round trip — without
 * touching a table, which matters because this runs from every replica every
 * few seconds and a probe that reads a table takes a lock queue with it.
 *
 * It deliberately does NOT check migrations, replication lag or table counts.
 * Those are deploy-time and monitoring concerns; folding them in here means a
 * pending migration pulls every instance out of the load balancer.
 */
final readonly class DatabaseProbe implements HealthProbe
{
    public function __construct(
        private ?DatabasePort $db,
        /** Slower than this and the result is Degraded rather than Up. */
        private float $degradedAfterMs = 250.0,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): ProbeResult
    {
        if ($this->db === null) {
            return ProbeResult::skipped($this->name(), 'no DatabasePort is bound');
        }

        $started = microtime(true);

        try {
            $row = $this->db->queryOne('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            // Never rethrown: a health endpoint that 500s on a broken dependency
            // reports nothing an orchestrator can act on.
            return ProbeResult::down(
                $this->name(),
                self::elapsed($started),
                // The exception CLASS, not its message. A PDO message routinely
                // contains the host, the port, the database name and sometimes
                // the user — and this response is one of the most reachable
                // things the application serves.
                'query failed (' . $e::class . ')',
            );
        }

        $elapsed = self::elapsed($started);

        if ($row === null || ($row['ok'] ?? null) === null) {
            // Connected, and the answer makes no sense. Rare, and worth its own
            // message: it usually means a proxy or pooler in front of the
            // database is answering instead of the database.
            return ProbeResult::down($this->name(), $elapsed, 'the server answered without a result row');
        }

        return $elapsed > $this->degradedAfterMs
            ? ProbeResult::degraded($this->name(), $elapsed, 'slow response')
            : ProbeResult::up($this->name(), $elapsed);
    }

    private static function elapsed(float $started): float
    {
        return (microtime(true) - $started) * 1000;
    }
}
