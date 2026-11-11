<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\Health\Application\Services\HealthService;
use Plugins\Health\Domain\Contracts\HealthProbe;
use Plugins\Health\Infrastructure\Config\HealthConfig;
use Plugins\Health\Infrastructure\Probes\CacheProbe;
use Plugins\Health\Infrastructure\Probes\DatabaseProbe;
use Plugins\Health\Infrastructure\Probes\QueueProbe;
use Plugins\Health\Infrastructure\Probes\StorageProbe;

/**
 * Assembles the probes for whatever this process actually bound.
 *
 * A port that is absent produces a SKIPPED probe rather than no probe, so the
 * report says "not bound" instead of quietly omitting the row. An operator
 * reading a report with no `queue` line cannot tell whether the queue is fine
 * or whether nobody wired it.
 *
 * Note what is NOT probed: DocumentStorePort. Firestore bills `count()` per
 * 1,000 index entries and every read, so a probe running from every replica
 * every few seconds would be a standing charge. A document store's health is a
 * monitoring concern, not a readiness one.
 */
final class HealthFactory
{
    public static function make(
        HealthConfig $config,
        ?DatabasePort $db = null,
        ?CachePort $cache = null,
        ?QueuePort $queue = null,
        ?StoragePort $storage = null,
        ?ClockPort $clock = null,
    ): HealthService {
        /** @var list<HealthProbe> $probes */
        $probes = [];

        $slow = $config->degradedAfterMs;

        if ($config->probeDatabase) {
            $probes[] = $slow > 0
                ? new DatabaseProbe($db, (float) $slow)
                : new DatabaseProbe($db);
        }

        if ($config->probeCache) {
            $probes[] = $slow > 0
                ? new CacheProbe($cache, (float) $slow)
                : new CacheProbe($cache);
        }

        if ($config->probeQueue) {
            $probes[] = $slow > 0
                ? new QueueProbe($queue, $config->queueName, $config->queueMaxDepth, (float) $slow)
                : new QueueProbe($queue, $config->queueName, $config->queueMaxDepth);
        }

        if ($config->probeStorage) {
            $probes[] = $slow > 0
                ? new StorageProbe($storage, (float) $slow)
                : new StorageProbe($storage);
        }

        return new HealthService($probes, $config, $clock);
    }
}
