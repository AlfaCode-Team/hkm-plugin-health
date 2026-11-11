<?php

declare(strict_types=1);

namespace Plugins\Health\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Health\API\Contracts\HealthCheckerContract;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\HealthReport;
use Plugins\Health\Domain\ProbeResult;
use Plugins\Health\Infrastructure\Config\HealthConfig;

/**
 * LIVENESS AND READINESS ARE DIFFERENT QUESTIONS, and conflating them is the
 * mistake this service is arranged to prevent.
 *
 *   live()  — "is this process alive?" Touches NOTHING external. Always 200
 *             unless PHP itself cannot answer.
 *   ready() — "can this process serve traffic?" Checks every dependency.
 *
 * An orchestrator does different things with them: a failed LIVENESS probe
 * RESTARTS the container, a failed READINESS probe takes it out of the load
 * balancer and leaves it running. So pointing a liveness probe at the database
 * means that during a database blip Kubernetes kills every replica at once —
 * turning a recoverable dependency outage into a dependency outage plus a
 * restart storm, with no capacity left when the database returns. A restart has
 * also fixed nothing, because the application was never the broken part.
 *
 * That is why `/health` does not touch the database even though it would be
 * easy, and why the README says which probe to point where.
 */
final class HealthService implements HealthCheckerContract
{
    /** @var list<HealthProbe> */
    private array $probes;

    private ?HealthReport $cached = null;

    private ?int $cachedAt = null;

    /** @param list<HealthProbe> $probes */
    public function __construct(
        array $probes,
        private readonly HealthConfig $config,
        private readonly ?ClockPort $clock = null,
    ) {
        $this->probes = $probes;
    }

    /**
     * Liveness. Deliberately empty of checks.
     *
     * An empty report is Up, which is the correct answer: this endpoint
     * responding at all IS the check. Routing, the pipeline, the container and
     * PHP are all proven by the response arriving.
     */
    public function live(): HealthReport
    {
        return HealthReport::of([]);
    }

    public function ready(): HealthReport
    {
        if ($this->config->cacheTtl > 0 && $this->cached !== null && $this->cachedAt !== null) {
            if (($this->now() - $this->cachedAt) < $this->config->cacheTtl) {
                return $this->cached;
            }
        }

        $results = [];

        foreach ($this->probes as $probe) {
            // Each probe is responsible for not throwing, and this is the belt
            // for the braces: one badly-behaved probe must not turn the health
            // endpoint itself into a 500, which is the one response an
            // orchestrator cannot interpret.
            try {
                $results[] = $probe->check();
            } catch (\Throwable $e) {
                $results[] = ProbeResult::down($probe->name(), null, 'the probe threw (' . $e::class . ')');
            }
        }

        $report = HealthReport::of($results);

        if ($this->config->cacheTtl > 0) {
            $this->cached   = $report;
            $this->cachedAt = $this->now();
        }

        return $report;
    }

    public function probe(string $name): ?ProbeResult
    {
        foreach ($this->probes as $probe) {
            if ($probe->name() === $name) {
                try {
                    return $probe->check();
                } catch (\Throwable $e) {
                    return ProbeResult::down($name, null, 'the probe threw (' . $e::class . ')');
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public function probeNames(): array
    {
        return array_values(array_map(static fn (HealthProbe $p): string => $p->name(), $this->probes));
    }

    /**
     * May this caller see probe DETAIL?
     *
     * With no HEALTH_TOKEN configured, yes — an application behind a private
     * network should not have to set one.
     *
     * With a token configured, the probe still ANSWERS without it (the status
     * and the HTTP code), and only the per-dependency detail is withheld. That
     * split is the point: a load balancer or kubelet cannot carry a bearer
     * token, so gating the whole endpoint would mean either exposing the detail
     * publicly or having no probe at all. What is withheld is the part that is
     * reconnaissance — which dependencies exist, which one is broken, and how
     * slow each is.
     */
    public function authorises(?string $presented): bool
    {
        if ($this->config->token === null) {
            return true;
        }

        // Constant-time: a token compared with === leaks its prefix through
        // response timing, and this endpoint is designed to be hit repeatedly.
        return $presented !== null && hash_equals($this->config->token, $presented);
    }

    private function now(): int
    {
        return $this->clock?->timestamp() ?? time();
    }
}
