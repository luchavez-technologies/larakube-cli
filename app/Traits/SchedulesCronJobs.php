<?php

namespace App\Traits;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Timezone handling shared by every command that deploys a CronJob.
 *
 * Kubernetes reads a bare cron expression in the kube-controller-manager's
 * timezone — UTC on essentially every cluster — so "3am" silently means 11am in
 * Manila and 8pm in Los Angeles. Every CronJob this CLI ships must therefore
 * set an explicit `timeZone` (Kubernetes >= 1.27), and every command that
 * schedules one should resolve it the same way.
 */
trait SchedulesCronJobs
{
    /**
     * The operator's own timezone, for scheduling.
     *
     * Kubernetes reads a CronJob schedule in the controller-manager's timezone
     * — UTC almost everywhere — so an unqualified "3am" is 11am in Manila and
     * 8pm in Los Angeles. Defaulting to the machine running the command is the
     * least surprising behaviour; the resolved zone is always printed.
     */
    protected function detectTimezone(): string
    {
        $link = @readlink('/etc/localtime');

        if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m) === 1) {
            $zone = $m[1];

            if (in_array($zone, timezone_identifiers_list(), true)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    /**
     * A human reading of when a cron expression next fires, for confirming the
     * schedule means what the operator thinks. Handles only the common
     * "minute hour * * *" shape; anything else is echoed back unchanged.
     */
    protected function describeSchedule(string $cron, string $timezone): string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];

        if (count($parts) !== 5 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return "{$cron} ({$timezone})";
        }

        $local = sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0]);

        try {
            $utc = (new DateTimeImmutable("today {$local}", new DateTimeZone($timezone)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('H:i');
        } catch (Exception) {
            return "{$cron} ({$timezone})";
        }

        return "{$local} {$timezone}  (= {$utc} UTC)";
    }
}
