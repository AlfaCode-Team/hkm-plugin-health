<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Probes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\ProbeResult;

/**
 * Can the queue be reached, and is its backlog within bounds?
 *
 * `size()` only. Pushing a probe job would put a message on a real queue that a
 * real worker would then have to handle, every few seconds, from every replica.
 *
 * THE BACKLOG CEILING IS DEGRADED, NEVER DOWN. A deep queue means the workers
 * are behind, which is a reason to alert and to scale — it is not a reason to
 * take the HTTP instances out of the load balancer, and doing so stops the
 * application accepting the very work that would drain. `HEALTH_QUEUE_MAX_DEPTH`
 * is off by default for the same reason: a number chosen without knowing the
 * traffic turns a normal burst into a page.
 */
final readonly class QueueProbe implements HealthProbe
{
    public function __construct(
        private ?QueuePort $queue,
        private string $queueName = 'default',
        /** 0 disables the depth check entirely. */
        private int $maxDepth = 0,
        private float $degradedAfterMs = 150.0,
    ) {}

    public function name(): string
    {
        return 'queue';
    }

    public function check(): ProbeResult
    {
        if ($this->queue === null) {
            return ProbeResult::skipped($this->name(), 'no QueuePort is bound');
        }

        $started = microtime(true);

        try {
            $size = $this->queue->size($this->queueName);
        } catch (\Throwable $e) {
            return ProbeResult::down($this->name(), self::elapsed($started), 'failed (' . $e::class . ')');
        }

        $elapsed = self::elapsed($started);

        if ($this->maxDepth > 0 && $size > $this->maxDepth) {
            return ProbeResult::degraded(
                $this->name(),
                $elapsed,
                "backlog {$size} is over the configured ceiling {$this->maxDepth}",
            );
        }

        if ($elapsed > $this->degradedAfterMs) {
            return ProbeResult::degraded($this->name(), $elapsed, 'slow response');
        }

        return ProbeResult::up($this->name(), $elapsed);
    }

    private static function elapsed(float $started): float
    {
        return (microtime(true) - $started) * 1000;
    }
}
