<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Projects\Deployment\ProjectDeployer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts the deployment of an approved project and leaves someone watching it.
 * Starting is quick: the work itself happens in the runner, outside the API.
 */
class DeployProject implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(ProjectDeployer $deployer): void
    {
        $deployer->start($this->project);

        WatchProjectDeployment::dispatch($this->project)->delay(now()->addSeconds(WatchProjectDeployment::INTERVAL_SECONDS));
    }

    public function failed(?Throwable $exception): void
    {
        $this->project->markFailed('The deployment could not start: '.($exception?->getMessage() ?? 'the job was lost.'));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['project', 'project:deploy', Project::class.':'.$this->project->id];
    }
}
