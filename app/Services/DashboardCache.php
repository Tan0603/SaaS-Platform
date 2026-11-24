<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant cache of the dashboard numbers and chart data (one payload).
 *
 * Tenant separation comes from CacheTenancyBootstrapper (every Cache call is tagged `tenant<id>` while a tenant
 * is initialised). The key contains today's date, so the overdue numbers refresh at midnight on their own.
 * flush() forgets today's key (the only key anyone reads); older dated keys simply expire via the TTL, so a
 * version counter is not needed. With no tenant initialised, get() computes directly (nothing cached) and
 * flush() does nothing, so neither can ever read or clear an untagged (global) key.
 */
class DashboardCache
{
    public const TTL_SECONDS = 600;

    public static function key(): string
    {
        return 'dashboard:data:'.today()->toDateString();
    }

    /**
     * Cards and charts in ONE payload, so one flush invalidates both.
     *
     * @return array{stats: array<string, int|string>, charts: array<string, mixed>}
     */
    public static function get(): array
    {
        $compute = fn () => [
            'stats' => app(DashboardStatsService::class)->compute(),
            'charts' => app(DashboardChartsService::class)->compute(),
        ];

        if (! tenancy()->initialized) {
            return $compute();
        }

        return Cache::remember(self::key(), self::TTL_SECONDS, $compute);
    }

    public static function flush(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        Cache::forget(self::key());
    }

    /** Flush once the surrounding transaction commits (immediately when there is none); a rollback flushes nothing. */
    public static function flushAfterCommit(): void
    {
        DB::afterCommit(fn () => self::flush());
    }
}
