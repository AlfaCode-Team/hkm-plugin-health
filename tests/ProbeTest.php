<?php

declare(strict_types=1);

namespace Tests\Plugins\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Health\Domain\HealthStatus;
use Plugins\Health\Infrastructure\Probes\CacheProbe;
use Plugins\Health\Infrastructure\Probes\DatabaseProbe;
use Plugins\Health\Infrastructure\Probes\QueueProbe;
use Plugins\Health\Infrastructure\Probes\StorageProbe;
use Tests\Plugins\Health\Support\FakeCache;
use Tests\Plugins\Health\Support\FakeDatabase;
use Tests\Plugins\Health\Support\FakeQueue;
use Tests\Plugins\Health\Support\FakeStorage;

#[CoversClass(DatabaseProbe::class)]
#[CoversClass(CacheProbe::class)]
#[CoversClass(QueueProbe::class)]
#[CoversClass(StorageProbe::class)]
final class ProbeTest extends TestCase
{
    // ── A probe never throws ──────────────────────────────────────────────────

    public function test_no_probe_ever_throws(): void
    {
        $boom = new \RuntimeException('connection refused');

        // A health endpoint that 500s on a broken dependency reports nothing an
        // orchestrator can act on. Every one of these must come back as data.
        $probes = [
            new DatabaseProbe(new FakeDatabase(throw: $boom)),
            new CacheProbe(new FakeCache(throw: $boom)),
            new QueueProbe(new FakeQueue(throw: $boom)),
            new StorageProbe(new FakeStorage(throw: $boom)),
        ];

        foreach ($probes as $probe) {
            $result = $probe->check();

            self::assertSame(HealthStatus::Down, $result->status, $probe->name());
            self::assertNotNull($result->detail, $probe->name());
        }
    }

    public function test_a_failure_detail_names_the_exception_class_not_its_message(): void
    {
        // A PDO message routinely carries the host, the port, the database name
        // and sometimes the user — and this response is one of the most
        // reachable things the application serves.
        $result = (new DatabaseProbe(new FakeDatabase(
            throw: new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused at db.internal:3306 for user app_rw'),
        )))->check();

        self::assertStringNotContainsString('db.internal', (string) $result->detail);
        self::assertStringNotContainsString('app_rw', (string) $result->detail);
        self::assertStringNotContainsString('3306', (string) $result->detail);
        self::assertStringContainsString('RuntimeException', (string) $result->detail);
    }

    // ── Absent ports ──────────────────────────────────────────────────────────

    public function test_an_unbound_port_is_skipped_not_up_and_not_down(): void
    {
        foreach ([
            new DatabaseProbe(null),
            new CacheProbe(null),
            new QueueProbe(null),
            new StorageProbe(null),
        ] as $probe) {
            $result = $probe->check();

            // Up would be a lie an operator acts on; Down would make every
            // application without a queue permanently unhealthy.
            self::assertSame(HealthStatus::Skipped, $result->status, $probe->name());
            self::assertNull($result->durationMs, $probe->name());
        }
    }

    // ── Database ──────────────────────────────────────────────────────────────

    public function test_the_database_probe_passes_on_a_result_row(): void
    {
        $result = (new DatabaseProbe(new FakeDatabase()))->check();

        self::assertSame(HealthStatus::Up, $result->status);
        self::assertNotNull($result->durationMs);
    }

    public function test_a_connected_database_with_no_row_is_down(): void
    {
        // Usually a pooler or proxy answering instead of the database.
        foreach ([null, []] as $row) {
            $result = (new DatabaseProbe(new FakeDatabase(row: $row)))->check();

            self::assertSame(HealthStatus::Down, $result->status);
            self::assertStringContainsString('without a result row', (string) $result->detail);
        }
    }

    public function test_a_slow_database_is_degraded_not_down(): void
    {
        $result = (new DatabaseProbe(new FakeDatabase(delaySeconds: 0.05), degradedAfterMs: 10.0))->check();

        // Degraded is worth alerting on; it is not a reason to remove capacity
        // while the remaining instances are already struggling.
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertStringContainsString('slow', (string) $result->detail);
    }

    // ── Cache: the write-and-read-back ────────────────────────────────────────

    public function test_the_cache_probe_catches_a_cache_that_accepts_writes_and_loses_them(): void
    {
        // THE reason this probe writes. A full Redis evicting instantly, or a
        // read-only replica, answers every read with a miss — which is
        // indistinguishable from a cold cache, so a read-only probe calls it
        // healthy while every cached value in the application evaporates.
        $result = (new CacheProbe(new FakeCache(amnesiac: true)))->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertStringContainsString('did not read back', (string) $result->detail);
    }

    public function test_a_working_cache_passes(): void
    {
        self::assertSame(HealthStatus::Up, (new CacheProbe(new FakeCache()))->check()->status);
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    public function test_a_deep_backlog_is_degraded_never_down(): void
    {
        $result = (new QueueProbe(new FakeQueue(size: 5_000), maxDepth: 100))->check();

        // Taking the HTTP instances out of the load balancer stops the
        // application accepting the very work that would drain the queue.
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertStringContainsString('5000', (string) $result->detail);
    }

    public function test_the_depth_ceiling_is_off_by_default(): void
    {
        // A number chosen without knowing the traffic turns a normal burst into
        // a page.
        self::assertSame(HealthStatus::Up, (new QueueProbe(new FakeQueue(size: 1_000_000)))->check()->status);
    }

    // ── Storage ───────────────────────────────────────────────────────────────

    public function test_the_storage_probe_is_read_only_and_a_miss_is_a_pass(): void
    {
        $storage = new FakeStorage();

        $result = (new StorageProbe($storage))->check();

        // The question is "did the backend answer", not "is the file there".
        self::assertSame(HealthStatus::Up, $result->status);
        self::assertCount(1, $storage->existsCalls);
        // Under a reserved prefix, so nothing real can ever be addressed.
        self::assertStringStartsWith('.health-probe/', $storage->existsCalls[0]);
    }

    public function test_the_storage_probe_path_is_different_every_time(): void
    {
        $storage = new FakeStorage();
        $probe   = new StorageProbe($storage);

        $probe->check();
        $probe->check();

        // A fixed path would eventually be created by someone, turning the
        // probe into a test of that file's existence.
        self::assertNotSame($storage->existsCalls[0], $storage->existsCalls[1]);
    }

    // ── Naming ────────────────────────────────────────────────────────────────

    public function test_probe_names_are_stable_and_lower_case(): void
    {
        // These names are in URLs (/health/database) and in dashboards.
        self::assertSame('database', (new DatabaseProbe(null))->name());
        self::assertSame('cache', (new CacheProbe(null))->name());
        self::assertSame('queue', (new QueueProbe(null))->name());
        self::assertSame('storage', (new StorageProbe(null))->name());
    }
}
