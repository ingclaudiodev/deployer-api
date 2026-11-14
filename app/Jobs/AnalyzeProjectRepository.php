<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Analyses the repository of a project. Queued because the model takes longer
 * than a request may last. One analysis per project at a time; it is never
 * retried on its own, since every run is a billed call to the model.
 *
 * Three times have to stay in this order: `$timeout`, then the timeout of the
 * Horizon supervisor, then the queue's `retry_after`. With a supervisor timeout
 * below `$timeout` Horizon kills the worker mid-analysis when it scales down;
 * with a `retry_after` below it the queue hands the job to a second worker
 * while the first is still waiting for the model.
 */
class AnalyzeProjectRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 540;

    /** Seconds the lock of an analysis lasts if the job dies without releasing it. */
    public int $uniqueFor = 600;

    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(ProjectAnalyzer $analyzer): void
    {
        $analyzer->analyze($this->project);
    }

    /**
     * The owner sees the project as failed and can ask for the analysis again.
     */
    public function failed(?Throwable $exception): void
    {
        $this->project->markFailed('The analysis failed: '.$this->reason($exception));

        app(ProjectProgress::class)->error($this->project, ProjectProgress::ANALYSIS, $this->project->failure_reason ?? 'The analysis failed.');
    }

    /**
     * Why it failed, in words the owner can act on. When the worker running the
     * job dies mid-way (its instance went away, its process was stopped) the
     * queue only finds out later, and all it says is that the job "has been
     * attempted too many times": true for the queue, meaningless on the screen.
     */
    private function reason(?Throwable $exception): string
    {
        if ($exception instanceof TimeoutExceededException) {
            return "it took longer than the {$this->timeout} seconds an analysis may last. Ask for it again.";
        }

        if ($exception === null || $exception instanceof MaxAttemptsExceededException) {
            return 'the worker running it stopped before it finished, so nothing was saved. Ask for it again.';
        }

        return $exception->getMessage();
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['project', 'project:analysis', Project::class.':'.$this->project->id];
    }
}
