<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Analyses the repository of a project. Queued because the model takes longer
 * than a request may last. One analysis per project at a time; it is never
 * retried on its own, since every run is a billed call to the model.
 *
 * The queue's `retry_after` must be longer than `$timeout`, or the queue hands
 * the job to a second worker while the first is still waiting for the model.
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
        $this->project->markFailed('The analysis failed: '.($exception?->getMessage() ?? 'the job was lost.'));

        app(ProjectProgress::class)->error($this->project, ProjectProgress::ANALYSIS, $this->project->failure_reason ?? 'The analysis failed.');
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['project', 'project:analysis', Project::class.':'.$this->project->id];
    }
}
