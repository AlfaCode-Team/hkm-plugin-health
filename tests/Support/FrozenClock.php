<?php

declare(strict_types=1);

namespace Tests\Plugins\Health\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

final class FrozenClock implements ClockPort
{
    public function __construct(private int $timestamp = 1_700_000_000) {}

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->timestamp);
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
