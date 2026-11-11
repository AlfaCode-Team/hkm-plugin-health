<?php

declare(strict_types=1);

namespace Tests\Plugins\Health\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;

final class FakeStorage implements StoragePort
{
    /** @var list<string> every path exists() was asked about */
    public array $existsCalls = [];

    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function exists(string $path): bool
    {
        $this->existsCalls[] = $path;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return false;
    }

    /** Writing from a probe is the mistake; these make it a test failure. */
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        throw new \LogicException('a health probe must never write to storage');
    }

    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string
    {
        throw new \LogicException('a health probe must never write to storage');
    }

    public function get(string $path): string { throw new \LogicException('not used'); }
    public function readStream(string $path) { throw new \LogicException('not used'); }
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string { return ''; }
    public function delete(string $path): bool { return true; }
}
