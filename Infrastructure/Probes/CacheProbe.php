<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Probes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\ProbeResult;

/**
 * Write a value, read it back, delete it.
 *
 * A read alone is not enough, and this is the one probe that legitimately
 * writes. The failure it exists to catch is a cache that ACCEPTS a write and
 * cannot return it — a full Redis with an eviction policy that drops the key
 * immediately, a replica in read-only mode, a misconfigured cluster slot. All
 * of those answer a read with "miss", which is indistinguishable from a cold
 * cache, so a read-only probe calls them healthy while every cached value in
 * the application silently evaporates.
 *
 * The key is namespaced and given a short TTL so that an application whose
 * cache is genuinely full is not made worse by the probe, and so the key cannot
 * collide with anything real.
 */
final readonly class CacheProbe implements HealthProbe
{
    private const KEY_PREFIX = 'health:probe:';
    private const TTL        = 10;

    public function __construct(
        private ?CachePort $cache,
        private float $degradedAfterMs = 100.0,
    ) {}

    public function name(): string
    {
        return 'cache';
    }

    public function check(): ProbeResult
    {
        if ($this->cache === null) {
            return ProbeResult::skipped($this->name(), 'no CachePort is bound');
        }

        // Unique per check, so two replicas probing at once cannot read each
        // other's value and mask a cache that is not actually storing anything.
        $key      = self::KEY_PREFIX . bin2hex(random_bytes(8));
        $expected = (string) microtime(true);
        $started  = microtime(true);

        try {
            $this->cache->set($key, $expected, self::TTL);
            $actual = $this->cache->get($key);
            $this->cache->delete($key);
        } catch (\Throwable $e) {
            return ProbeResult::down($this->name(), self::elapsed($started), 'failed (' . $e::class . ')');
        }

        $elapsed = self::elapsed($started);

        if ($actual !== $expected) {
            return ProbeResult::down(
                $this->name(),
                $elapsed,
                'a written value did not read back — the cache is accepting writes and losing them',
            );
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
