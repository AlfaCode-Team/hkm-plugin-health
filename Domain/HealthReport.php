<?php

declare(strict_types=1);

namespace Plugins\Health\Domain;

/**
 * Every probe's answer, plus the one status a load balancer reads.
 */
final readonly class HealthReport
{
    /** @param list<ProbeResult> $results */
    private function __construct(
        public HealthStatus $status,
        public array $results,
    ) {}

    /** @param list<ProbeResult> $results */
    public static function of(array $results): self
    {
        // Skipped does not count toward the overall status — an application with
        // no queue must not be permanently unhealthy because the queue probe
        // found nothing to probe — and that is enforced in HealthStatus::worst(),
        // which escalates only on Down and Degraded.
        //
        // An earlier version of this method ALSO filtered Skipped out here. A
        // revert check proved it dead: with the filter removed every test still
        // passed, because worst() already ignores it and an all-Skipped set
        // reaches the same Up either way. It is gone rather than kept as
        // reassurance, since code that looks like a guarantee and provides none
        // is how the real guarantee stops being noticed.
        $status = HealthStatus::worst(
            ...array_map(static fn (ProbeResult $r): HealthStatus => $r->status, $results),
        );

        return new self($status, $results);
    }

    public function isHealthy(): bool
    {
        return !$this->status->isFailure();
    }

    /**
     * 200 when serving is possible, 503 when it is not.
     *
     * Degraded answers 200 on purpose. A slow dependency is worth reporting and
     * worth alerting on, but pulling the instance out of the load balancer for
     * it removes capacity at exactly the moment the remaining instances are
     * already struggling — which is how a slowdown becomes an outage.
     */
    public function httpStatus(): int
    {
        return $this->isHealthy() ? 200 : 503;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $checks = [];

        foreach ($this->results as $result) {
            $checks[$result->name] = $result->toArray();
        }

        return ['status' => $this->status->value, 'checks' => $checks];
    }
}
