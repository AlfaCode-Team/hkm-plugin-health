<?php

declare(strict_types=1);

namespace Plugins\Health\Domain\Contracts;

use Plugins\Health\Domain\ProbeResult;

/**
 * One dependency's reachability check.
 *
 * A probe MUST NOT THROW. A health endpoint whose own failure is a 500 tells an
 * orchestrator nothing it can act on, and the whole point is to report a broken
 * dependency as data. Every implementation returns ProbeResult::down() instead.
 *
 * A probe MUST ALSO BE CHEAP AND READ-ONLY. These run every few seconds from
 * every replica, plus whatever the load balancer adds: a probe that writes, or
 * that scans, is a self-inflicted load test. The storage probe reads; the cache
 * probe writes one short-lived key to its own reserved namespace, because a
 * cache that accepts a write and cannot return it is broken in a way a read
 * alone would not notice.
 */
interface HealthProbe
{
    /** Short, stable, lower-case: 'database', 'cache', 'queue', 'storage'. */
    public function name(): string;

    public function check(): ProbeResult;
}
