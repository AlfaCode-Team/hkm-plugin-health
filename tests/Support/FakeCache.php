<?php

declare(strict_types=1);

namespace Tests\Plugins\Health\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;

final class FakeCache implements CachePort
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function __construct(
        private readonly ?\Throwable $throw = null,
        /**
         * Accept writes and return nothing — a full Redis whose eviction policy
         * drops the key immediately, or a replica in read-only mode. This is the
         * failure a read-only probe cannot see.
         */
        private readonly bool $amnesiac = false,
    ) {}

    public function get(string $key): mixed
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->amnesiac ? null : ($this->store[$key] ?? null);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool { return isset($this->store[$key]); }
    public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
    public function increment(string $key, int $by = 1): int { return $by; }
    public function deletePattern(string $pattern): int { return 0; }
    public function flush(): bool { $this->store = []; return true; }
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock { throw new \LogicException('not used'); }
    public function restoreLock(string $name, string $owner): Lock { throw new \LogicException('not used'); }
}
