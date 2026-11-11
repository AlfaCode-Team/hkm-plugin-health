<?php

declare(strict_types=1);

namespace Plugins\Health\Domain;

enum HealthStatus: string
{
    case Up = 'up';

    /** Reachable, but answering slowly enough to be worth knowing about. */
    case Degraded = 'degraded';

    case Down = 'down';

    /**
     * Not bound, so not checked.
     *
     * Distinct from Up and from Down, and both distinctions matter. Reporting an
     * absent dependency as Up is a lie an operator will act on; reporting it as
     * Down makes every application that does not use a queue permanently
     * unhealthy.
     */
    case Skipped = 'skipped';

    /** Does this status stop the application serving traffic? */
    public function isFailure(): bool
    {
        return $this === self::Down;
    }

    /** The worst of a set, for rolling probe results into one answer. */
    public static function worst(self ...$statuses): self
    {
        $worst = self::Up;

        foreach ($statuses as $status) {
            if ($status === self::Down) {
                return self::Down;
            }

            if ($status === self::Degraded) {
                $worst = self::Degraded;
            }
        }

        return $worst;
    }
}
