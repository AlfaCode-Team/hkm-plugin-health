<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Probes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\ProbeResult;

/**
 * Is the storage backend answering?
 *
 * `exists()` on a path that is not expected to exist. READ-ONLY, deliberately:
 * on S3 this is a HEAD request, and on a local disk a stat. Either way it proves
 * credentials, the bucket or mount, and the round trip.
 *
 * **A false answer is a PASS.** The question is "did the backend answer", not
 * "is the file there" — so `false` is success and only a thrown exception is a
 * failure. An earlier instinct is to probe a sentinel file that must exist, but
 * that needs the file deployed everywhere and turns a missing artefact into a
 * fleet-wide outage.
 *
 * Writing would be worse again: this runs from every replica every few seconds,
 * and S3 bills per request on top of it.
 */
final readonly class StorageProbe implements HealthProbe
{
    public function __construct(
        private ?StoragePort $storage,
        private float $degradedAfterMs = 500.0,
    ) {}

    public function name(): string
    {
        return 'storage';
    }

    public function check(): ProbeResult
    {
        if ($this->storage === null) {
            return ProbeResult::skipped($this->name(), 'no StoragePort is bound');
        }

        $started = microtime(true);

        try {
            // A path under a reserved prefix, so a hit is impossible and nothing
            // real can ever be addressed by accident.
            $this->storage->exists('.health-probe/' . bin2hex(random_bytes(6)));
        } catch (\Throwable $e) {
            return ProbeResult::down($this->name(), self::elapsed($started), 'failed (' . $e::class . ')');
        }

        $elapsed = self::elapsed($started);

        // A higher threshold than the others on purpose: object storage over the
        // network is legitimately slower than a local database, and a tight
        // bound here would report Degraded continuously on a healthy S3.
        return $elapsed > $this->degradedAfterMs
            ? ProbeResult::degraded($this->name(), $elapsed, 'slow response')
            : ProbeResult::up($this->name(), $elapsed);
    }

    private static function elapsed(float $started): float
    {
        return (microtime(true) - $started) * 1000;
    }
}
