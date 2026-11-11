<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Config;

final readonly class HealthConfig
{
    private function __construct(
        /**
         * Required to see probe DETAIL. The probe itself always answers without
         * it — see HealthService::authorises().
         */
        public ?string $token,
        /** Seconds to reuse a readiness result. 0 disables caching. */
        public int $cacheTtl,
        public string $queueName,
        public int $queueMaxDepth,
        public bool $probeDatabase,
        public bool $probeCache,
        public bool $probeQueue,
        public bool $probeStorage,
        /** 0 keeps each probe's own default. */
        public int $degradedAfterMs,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            token:           self::stringOrNull(env('HEALTH_TOKEN')),
            // OFF by default. A cached readiness answer is a STALE one, and the
            // window during which an orchestrator keeps sending traffic to a
            // broken instance is exactly this TTL. It exists for applications
            // whose probe traffic genuinely outweighs that risk, and those
            // operators should choose the number themselves.
            cacheTtl:        max(0, (int) env('HEALTH_CACHE_TTL', 0)),
            queueName:       trim((string) env('HEALTH_QUEUE_NAME', 'default')) ?: 'default',
            // OFF by default: a depth ceiling chosen without knowing the traffic
            // turns a normal burst into a page.
            queueMaxDepth:   max(0, (int) env('HEALTH_QUEUE_MAX_DEPTH', 0)),
            probeDatabase:   self::bool(env('HEALTH_PROBE_DATABASE', true)),
            probeCache:      self::bool(env('HEALTH_PROBE_CACHE', true)),
            probeQueue:      self::bool(env('HEALTH_PROBE_QUEUE', true)),
            probeStorage:    self::bool(env('HEALTH_PROBE_STORAGE', true)),
            degradedAfterMs: max(0, (int) env('HEALTH_DEGRADED_AFTER_MS', 0)),
        );
    }

    public static function of(
        ?string $token = null,
        int $cacheTtl = 0,
        string $queueName = 'default',
        int $queueMaxDepth = 0,
        bool $probeDatabase = true,
        bool $probeCache = true,
        bool $probeQueue = true,
        bool $probeStorage = true,
        int $degradedAfterMs = 0,
    ): self {
        return new self(
            $token,
            $cacheTtl,
            $queueName,
            $queueMaxDepth,
            $probeDatabase,
            $probeCache,
            $probeQueue,
            $probeStorage,
            $degradedAfterMs,
        );
    }

    private static function bool(mixed $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
