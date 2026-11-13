<?php

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Projects\Deployment\ProjectDeployer;
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

    public const int INTERVAL_SECONDS = 30;

    /** Looks before giving the deployment up for lost: a little over the runner's own timeout. */
    private const int MAX_LOOKS = 100;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Project $project,
        public string $buildId = '',
        public int $look = 1,
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
            $project->forceFill(['status' => ProjectStatus::Failed])->save();

            return;
        }

        self::dispatch($project, $buildId, $this->look + 1)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    /**
     * A look that could not be made (the runner did not answer) must not leave
     * the project "building" forever: the next look is still queued.
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->look < self::MAX_LOOKS) {
            self::dispatch($this->project, $this->buildId, $this->look + 1)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
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
