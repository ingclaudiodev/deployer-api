<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\DashboardMetrics;
use App\Services\Panel\SystemHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operations overview. What comes from the database is cheap and ships with
 * the page; what has to ask Redis and Horizon is deferred, so a slow or missing
 * Redis delays one card instead of the whole panel.
 */
class DashboardController extends Controller
{
    private const int SIGNUP_DAYS = 14;

    private const int RECENT_USERS = 8;

    public function __invoke(DashboardMetrics $metrics, SystemHealth $health): Response
    {
        return Inertia::render('panel/Dashboard', [
            // Closures: the 15 s reload asks only for `health` and must not rerun these queries.
            'stats' => fn (): array => $metrics->overview(),
            'roles' => fn (): array => $metrics->usersPerRole(),
            'signups' => fn (): array => $metrics->signupsPerDay(self::SIGNUP_DAYS),
            'recentUsers' => fn (): array => $metrics->recentUsers(self::RECENT_USERS),
            'system' => fn (): array => $metrics->system(),
            'horizonUrl' => url((string) config('horizon.path')),
            'health' => Inertia::defer(fn (): array => $health->snapshot()),
        ]);
    }
}
