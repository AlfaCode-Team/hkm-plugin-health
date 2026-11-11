<?php

declare(strict_types=1);

namespace Tests\Plugins\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Health\Application\Services\HealthService;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Domain\HealthReport;
use Plugins\Health\Domain\HealthStatus;
use Plugins\Health\Domain\ProbeResult;
use Plugins\Health\Infrastructure\Config\HealthConfig;
use Plugins\Health\Infrastructure\HealthFactory;
use Tests\Plugins\Health\Support\FakeCache;
use Tests\Plugins\Health\Support\FakeDatabase;
use Tests\Plugins\Health\Support\FrozenClock;

#[CoversClass(HealthService::class)]
#[CoversClass(HealthReport::class)]
#[CoversClass(HealthStatus::class)]
#[CoversClass(ProbeResult::class)]
#[CoversClass(HealthFactory::class)]
#[CoversClass(HealthConfig::class)]
final class HealthServiceTest extends TestCase
{
    // ── Liveness is not readiness ─────────────────────────────────────────────

    public function test_liveness_runs_no_probes_at_all(): void
    {
        // THE central property. A liveness probe that touches the database makes
        // an orchestrator RESTART every replica during a database blip — adding
        // a restart storm to an outage that a restart cannot fix, and leaving no
        // capacity when the dependency returns.
        $service = new HealthService([new ExplodingProbe()], HealthConfig::of());

        $report = $service->live();

        self::assertSame(HealthStatus::Up, $report->status);
        self::assertSame([], $report->results);
        self::assertSame(200, $report->httpStatus());
    }

    public function test_liveness_is_up_even_when_every_dependency_is_down(): void
    {
        $service = new HealthService(
            [new StubProbe('database', ProbeResult::down('database', 1.0, 'gone'))],
            HealthConfig::of(),
        );

        self::assertSame(200, $service->live()->httpStatus());
        self::assertSame(503, $service->ready()->httpStatus());
    }

    // ── Rolling up ────────────────────────────────────────────────────────────

    public function test_one_down_dependency_makes_readiness_503(): void
    {
        $service = new HealthService([
            new StubProbe('database', ProbeResult::up('database', 1.0)),
            new StubProbe('cache', ProbeResult::down('cache', 2.0, 'gone')),
        ], HealthConfig::of());

        $report = $service->ready();

        self::assertSame(HealthStatus::Down, $report->status);
        self::assertSame(503, $report->httpStatus());
    }

    public function test_degraded_still_answers_200(): void
    {
        $service = new HealthService([
            new StubProbe('database', ProbeResult::degraded('database', 900.0, 'slow')),
        ], HealthConfig::of());

        $report = $service->ready();

        self::assertSame(HealthStatus::Degraded, $report->status);
        // Removing the instance for slowness takes capacity away exactly when
        // the rest are already struggling.
        self::assertSame(200, $report->httpStatus());
    }

    public function test_skipped_probes_do_not_affect_the_overall_status(): void
    {
        $service = new HealthService([
            new StubProbe('database', ProbeResult::up('database', 1.0)),
            new StubProbe('queue', ProbeResult::skipped('queue')),
        ], HealthConfig::of());

        // An application with no queue must not be permanently unhealthy.
        self::assertSame(HealthStatus::Up, $service->ready()->status);
        // But the row is still reported, so an operator can tell "fine" from
        // "nobody wired it".
        self::assertArrayHasKey('queue', $service->ready()->toArray()['checks']);
    }

    public function test_an_all_skipped_report_is_up(): void
    {
        $service = new HealthService([new StubProbe('queue', ProbeResult::skipped('queue'))], HealthConfig::of());

        self::assertSame(HealthStatus::Up, $service->ready()->status);
    }

    public function test_worst_picks_the_worst(): void
    {
        self::assertSame(HealthStatus::Down, HealthStatus::worst(HealthStatus::Up, HealthStatus::Down, HealthStatus::Degraded));
        self::assertSame(HealthStatus::Degraded, HealthStatus::worst(HealthStatus::Up, HealthStatus::Degraded));
        self::assertSame(HealthStatus::Up, HealthStatus::worst(HealthStatus::Up, HealthStatus::Up));
        self::assertSame(HealthStatus::Up, HealthStatus::worst());
    }

    public function test_worst_does_not_escalate_on_skipped(): void
    {
        // This is where "Skipped does not count" actually lives. A revert check
        // showed HealthReport was filtering Skipped out as well, and that the
        // filter was dead — worst() alone is the guarantee, so it is pinned here
        // rather than inferred from a report-level test that passed either way.
        self::assertSame(HealthStatus::Up, HealthStatus::worst(HealthStatus::Skipped));
        self::assertSame(HealthStatus::Up, HealthStatus::worst(HealthStatus::Up, HealthStatus::Skipped));
        self::assertSame(HealthStatus::Degraded, HealthStatus::worst(HealthStatus::Skipped, HealthStatus::Degraded));
        self::assertSame(HealthStatus::Down, HealthStatus::worst(HealthStatus::Skipped, HealthStatus::Down));
        self::assertFalse(HealthStatus::Skipped->isFailure());
    }

    // ── A throwing probe cannot break the endpoint ────────────────────────────

    public function test_a_throwing_probe_becomes_a_down_result_not_a_500(): void
    {
        $service = new HealthService([new ExplodingProbe()], HealthConfig::of());

        $report = $service->ready();

        // A 500 is the one response an orchestrator cannot interpret.
        self::assertSame(503, $report->httpStatus());
        self::assertStringContainsString('the probe threw', (string) $report->results[0]->detail);
    }

    public function test_a_throwing_probe_does_not_stop_the_others_running(): void
    {
        $service = new HealthService([
            new ExplodingProbe(),
            new StubProbe('cache', ProbeResult::up('cache', 1.0)),
        ], HealthConfig::of());

        self::assertCount(2, $service->ready()->results);
    }

    // ── Caching ───────────────────────────────────────────────────────────────

    public function test_readiness_is_not_cached_by_default(): void
    {
        $probe   = new CountingProbe();
        $service = new HealthService([$probe], HealthConfig::of());

        $service->ready();
        $service->ready();

        // A cached readiness answer is a stale one, and the window in which an
        // orchestrator keeps routing to a broken instance IS the TTL.
        self::assertSame(2, $probe->calls);
    }

    public function test_caching_reuses_a_result_within_the_ttl_and_expires_after(): void
    {
        $probe   = new CountingProbe();
        $clock   = new FrozenClock();
        $service = new HealthService([$probe], HealthConfig::of(cacheTtl: 5), $clock);

        $service->ready();
        $service->ready();
        self::assertSame(1, $probe->calls, 'within the TTL');

        $clock->advance(5);
        $service->ready();
        self::assertSame(2, $probe->calls, 'after the TTL');
    }

    // ── The token split ───────────────────────────────────────────────────────

    public function test_with_no_token_configured_everything_is_authorised(): void
    {
        $service = new HealthService([], HealthConfig::of());

        // An application on a private network should not have to set one.
        self::assertTrue($service->authorises(null));
        self::assertTrue($service->authorises('anything'));
    }

    public function test_a_configured_token_must_match(): void
    {
        $service = new HealthService([], HealthConfig::of(token: 's3cret'));

        self::assertTrue($service->authorises('s3cret'));
        self::assertFalse($service->authorises(null));
        self::assertFalse($service->authorises('wrong'));
        self::assertFalse($service->authorises('s3cre'));
        self::assertFalse($service->authorises('s3secretX'));
    }

    // ── One probe by name ─────────────────────────────────────────────────────

    public function test_a_single_probe_can_be_run_by_name(): void
    {
        $service = new HealthService([new StubProbe('cache', ProbeResult::up('cache', 3.0))], HealthConfig::of());

        self::assertSame(HealthStatus::Up, $service->probe('cache')?->status);
        self::assertNull($service->probe('nope'));
        self::assertSame(['cache'], $service->probeNames());
    }

    // ── The factory ───────────────────────────────────────────────────────────

    public function test_the_factory_builds_a_probe_per_enabled_port(): void
    {
        $service = HealthFactory::make(
            HealthConfig::of(),
            db: new FakeDatabase(),
            cache: new FakeCache(),
        );

        self::assertSame(['database', 'cache', 'queue', 'storage'], $service->probeNames());

        $checks = $service->ready()->toArray()['checks'];

        // Bound ports answer; absent ones are reported as skipped rather than
        // omitted.
        self::assertSame('up', $checks['database']['status']);
        self::assertSame('skipped', $checks['queue']['status']);
    }

    public function test_a_disabled_probe_is_not_registered_at_all(): void
    {
        $service = HealthFactory::make(
            HealthConfig::of(probeQueue: false, probeStorage: false),
            db: new FakeDatabase(),
        );

        self::assertSame(['database', 'cache'], $service->probeNames());
    }

    public function test_the_result_shape_carries_no_host_or_credential(): void
    {
        $service = HealthFactory::make(HealthConfig::of(), db: new FakeDatabase());

        $encoded = (string) json_encode($service->ready()->toArray());

        // The whole response is one of the most reachable things an application
        // serves, so it must not enumerate the infrastructure.
        self::assertStringNotContainsStringIgnoringCase('password', $encoded);
        self::assertStringNotContainsStringIgnoringCase('dsn', $encoded);
        self::assertStringNotContainsStringIgnoringCase('mysql', $encoded);
    }
}

final class StubProbe implements HealthProbe
{
    public function __construct(private readonly string $name, private readonly ProbeResult $result) {}

    public function name(): string { return $this->name; }

    public function check(): ProbeResult { return $this->result; }
}

final class ExplodingProbe implements HealthProbe
{
    public function name(): string { return 'exploding'; }

    public function check(): ProbeResult { throw new \RuntimeException('boom'); }
}

final class CountingProbe implements HealthProbe
{
    public int $calls = 0;

    public function name(): string { return 'counting'; }

    public function check(): ProbeResult
    {
        $this->calls++;

        return ProbeResult::up('counting', 1.0);
    }
}
