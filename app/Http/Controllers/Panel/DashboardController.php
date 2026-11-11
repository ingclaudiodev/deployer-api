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
            'stats' => $metrics->overview(),
            'roles' => $metrics->usersPerRole(),
            'signups' => $metrics->signupsPerDay(self::SIGNUP_DAYS),
            'recentUsers' => $metrics->recentUsers(self::RECENT_USERS),
            'system' => $metrics->system(),
            'horizonUrl' => url((string) config('horizon.path')),
            'health' => Inertia::defer(fn (): array => $health->snapshot()),
        ]);
    }
}
