<?php

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Projects\Deployment\ProjectDeployer;
use App\Services\Projects\ProjectProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Looks at a running deployment once and, while it has not ended, queues the
 * next look. Each look is its own short job, so no worker is held for the
 * minutes a deployment takes.
 */
class WatchProjectDeployment implements ShouldQueue
{
    use Queueable;

    public const int INTERVAL_SECONDS = 10;

    /** Looks before giving the deployment up for lost: a little over the runner's own timeout. */
    private const int MAX_LOOKS = 300;

    /** Looks in a row that may fail before the deployment is given up. */
    private const int MAX_FAILED_LOOKS = 6;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Project $project,
        public string $buildId = '',
        public int $look = 1,
        public int $failedLooks = 0,
    ) {}

    public function handle(ProjectDeployer $deployer): void
    {
        $project = $this->project->refresh();
        $buildId = $this->buildId !== '' ? $this->buildId : (string) $project->deployment_build_id;

        // A newer approval took over: its own watcher follows that one.
        if ($project->status !== ProjectStatus::Building || $project->deployment_build_id !== $buildId) {
            return;
        }

        if (! $deployer->follow($project)) {
            return;
        }

        if ($this->look >= self::MAX_LOOKS) {
            $project->markFailed('The deployment did not finish in the time allowed.');
            app(ProjectProgress::class)->error($project, ProjectProgress::DEPLOYMENT, (string) $project->failure_reason);

            return;
        }

        self::dispatch($project, $buildId, $this->look + 1)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    /**
     * A look that could not be made (the runner did not answer, a bug in the
     * look itself) is tried again a few times. After that the project is told
     * failed, with the reason: "building" forever is the one thing it must not be.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?? 'the job was lost.';
        $progress = app(ProjectProgress::class);

        if ($this->failedLooks + 1 < self::MAX_FAILED_LOOKS && $this->look < self::MAX_LOOKS) {
            if ($this->failedLooks === 0) {
                $progress->output($this->project, ProjectProgress::DEPLOYMENT, ["No se pudo consultar el despliegue; se reintenta. Motivo: {$reason}"]);
            }

            self::dispatch($this->project, $this->buildId, $this->look + 1, $this->failedLooks + 1)->delay(now()->addSeconds(self::INTERVAL_SECONDS));

            return;
        }

        $project = $this->project->refresh();

        if ($project->status === ProjectStatus::Building) {
            $project->markFailed("Deployer could not follow the deployment: {$reason}");
            $progress->error($project, ProjectProgress::DEPLOYMENT, (string) $project->failure_reason);
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['project', 'project:deploy-watch', Project::class.':'.$this->project->id];
    }
}
