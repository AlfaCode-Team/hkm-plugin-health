<?php

declare(strict_types=1);

namespace Tests\Plugins\Health\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;

final class FakeQueue implements QueuePort
{
    public function __construct(
        private readonly int $size = 0,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function size(string $queue = 'default'): int
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->size;
    }

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string { return 'id'; }
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string { return 'id'; }
    public function pop(string $queue = 'default'): ?JobPayload { return null; }
    public function ack(JobPayload $payload): void {}
    public function release(JobPayload $payload, int $delay = 0): void {}
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void {}
}
