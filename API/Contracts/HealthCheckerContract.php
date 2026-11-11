<?php

declare(strict_types=1);

namespace Plugins\Health\API\Contracts;

use Plugins\Health\Domain\HealthReport;
use Plugins\Health\Domain\ProbeResult;

/**
 * The published surface of `health.probes`.
 *
 * Note what is NOT here: anything about the machine. Whether a host can run the
 * kernel at all — extensions, permissions, PHP version, directories — is
 * `hkm doctor`'s question and has exactly one authority. This answers a
 * different one: are the dependencies of THIS RUNNING PROCESS reachable right
 * now. A load balancer cannot shell in to ask doctor, and doctor cannot see the
 * ports a materialised container actually bound.
 */
interface HealthCheckerContract
{
    /** Liveness: is the process alive? Touches nothing external. */
    public function live(): HealthReport;

    /** Readiness: can the process serve traffic? Checks every dependency. */
    public function ready(): HealthReport;

    /** One dependency by name, or null when no such probe is registered. */
    public function probe(string $name): ?ProbeResult;

    /** @return list<string> */
    public function probeNames(): array;

    /** May this caller see per-dependency detail? See the service for the split. */
    public function authorises(?string $presented): bool;
}
