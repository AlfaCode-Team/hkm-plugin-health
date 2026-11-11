<?php

declare(strict_types=1);

namespace Tests\Plugins\Health\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that can answer, stall, or throw.
 *
 * The probes' logic is what this plugin owns — not whether MySQL answers
 * `SELECT 1` — so these doubles exist to drive every branch.
 */
final class FakeDatabase implements DatabasePort
{
    /** @param array<string, mixed>|null $row */
    public function __construct(
        private readonly ?\Throwable $throw = null,
        private readonly ?array $row = ['ok' => 1],
        private readonly float $delaySeconds = 0.0,
    ) {}

    public function queryOne(string $sql, array $params = []): ?array
    {
        if ($this->delaySeconds > 0) {
            usleep((int) ($this->delaySeconds * 1_000_000));
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->row;
    }

    public function query(string $sql, array $params = []): array { return []; }
    public function execute(string $sql, array $params = []): int { return 0; }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
    public function lastInsertId(?string $sequence = null): string { return '0'; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }
}
