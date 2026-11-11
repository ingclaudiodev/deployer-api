<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * Live state of what the API depends on: the database, Redis and the Horizon
 * workers. Every probe is fenced: a dependency that is down is the answer the
 * panel has to show, never an exception that takes the panel down with it.
 *
 * @phpstan-type Probe array{ok: bool, latencyMs: float|null}
 * @phpstan-type HorizonState array{status: 'running'|'paused'|'inactive'|'unreachable', processes: int, jobsPerMinute: int, recentJobs: int, failedJobs: int, maxWaitSeconds: int}
 */
class SystemHealth
{
    /**
     * @return array{database: Probe, redis: Probe, horizon: HorizonState}
     */
    public function snapshot(): array
    {
        return [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'redis' => $this->probe(fn () => Redis::connection()->command('ping')),
            'horizon' => $this->horizon(),
        ];
    }

    /**
     * @param  callable(): mixed  $check
     * @return Probe
     */
    private function probe(callable $check): array
    {
        $startedAt = hrtime(true);

        try {
            $check();
        } catch (Throwable) {
            return ['ok' => false, 'latencyMs' => null];
        }

        return ['ok' => true, 'latencyMs' => round((hrtime(true) - $startedAt) / 1_000_000, 1)];
    }

    /**
     * The same figures as Horizon's own dashboard header, read from its repositories.
     *
     * @return HorizonState
     */
    private function horizon(): array
    {
        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all());
            $jobs = app(JobRepository::class);

            return [
                'status' => match (true) {
                    $masters->isEmpty() => 'inactive',
                    $masters->every(fn (object $master): bool => data_get($master, 'status') === 'paused') => 'paused',
                    default => 'running',
                },
                'processes' => (int) collect(app(SupervisorRepository::class)->all())
                    ->sum(fn (object $supervisor): int => (int) array_sum((array) data_get($supervisor, 'processes', []))),
                'jobsPerMinute' => (int) app(MetricsRepository::class)->jobsProcessedPerMinute(),
                'recentJobs' => (int) $jobs->countRecent(),
                'failedJobs' => (int) $jobs->countRecentlyFailed(),
                'maxWaitSeconds' => (int) collect(app(WaitTimeCalculator::class)->calculate())->max(),
            ];
        } catch (Throwable) {
            return [
                'status' => 'unreachable',
                'processes' => 0,
                'jobsPerMinute' => 0,
                'recentJobs' => 0,
                'failedJobs' => 0,
                'maxWaitSeconds' => 0,
            ];
        }
    }
}
