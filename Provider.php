<?php

declare(strict_types=1);

namespace Plugins\Health;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\Health\API\Contracts\HealthCheckerContract;
use Plugins\Health\Application\Services\HealthService;
use Plugins\Health\Infrastructure\Config\HealthConfig;
use Plugins\Health\Infrastructure\HealthFactory;

/**
 * Health — wires the 'health.probes' domain.
 *
 * Declares NO requires[]. Every dependency it checks is an app-lifetime port
 * from the CoreContainer, and a port that is absent produces a Skipped probe
 * rather than a boot failure — so this plugin can be installed anywhere,
 * including in an application that has no queue or no object storage.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'health.probes';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [HealthCheckerContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(HealthConfig::class, static fn (): HealthConfig => HealthConfig::fromEnv());

        $container->bind(
            HealthCheckerContract::class,
            static fn (ModuleContainer $c): HealthService => HealthFactory::make(
                config: $c->make(HealthConfig::class),
                // Every port is optional. Resolving one that is not bound would
                // throw, and a health endpoint that cannot be constructed is the
                // one endpoint that must never be the thing that is broken.
                db:      $c->has(DatabasePort::class) ? $c->make(DatabasePort::class) : null,
                cache:   $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                queue:   $c->has(QueuePort::class) ? $c->make(QueuePort::class) : null,
                storage: $c->has(StoragePort::class) ? $c->make(StoragePort::class) : null,
                clock:   $c->has(ClockPort::class) ? $c->make(ClockPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Nothing. The routes are declared in module.json, and this plugin
        // registers no stage: a health endpoint that ran behind the
        // application's own filters would stop answering for exactly the
        // reasons it exists to report.
    }
}
