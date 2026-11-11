<?php

declare(strict_types=1);

namespace Plugins\Health\Domain;

/**
 * One dependency's answer.
 *
 * NOTHING HERE MAY CARRY A CREDENTIAL, a connection string or a host name. A
 * health endpoint is usually the most reachable thing an application exposes,
 * and one that enumerates its infrastructure is free reconnaissance. The detail
 * field is for a failure's shape ("timed out after 2000ms"), never for where it
 * was trying to connect.
 */
final readonly class ProbeResult
{
    private function __construct(
        public string $name,
        public HealthStatus $status,
        /** Round-trip in milliseconds, or null when nothing was attempted. */
        public ?float $durationMs,
        public ?string $detail = null,
    ) {}

    public static function up(string $name, float $durationMs): self
    {
        return new self($name, HealthStatus::Up, $durationMs);
    }

    public static function degraded(string $name, float $durationMs, string $detail): self
    {
        return new self($name, HealthStatus::Degraded, $durationMs, $detail);
    }

    public static function down(string $name, ?float $durationMs, string $detail): self
    {
        return new self($name, HealthStatus::Down, $durationMs, $detail);
    }

    public static function skipped(string $name, string $detail = 'not bound'): self
    {
        return new self($name, HealthStatus::Skipped, null, $detail);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['status' => $this->status->value];

        if ($this->durationMs !== null) {
            $out['duration_ms'] = round($this->durationMs, 1);
        }

        if ($this->detail !== null) {
            $out['detail'] = $this->detail;
        }

        return $out;
    }
}
