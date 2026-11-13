<?php

namespace App\Http\Controllers\Api\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\Deployment\ProjectDeployer;
use App\Services\Projects\ProjectProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the last analysis or deployment of the project did so far, line by line,
 * and the state the project is in. The same lines are pushed live as
 * `.project.progress`; this is how a screen that just opened catches up.
 *
 * Asking also moves a running deployment forward: the screen of its owner is
 * the one witness that is surely awake, while the queue may be asleep with the
 * instance or its chain of looks broken. At most one look every few seconds.
 */
class ProgressController extends Controller
{
    private const int SECONDS_BETWEEN_LOOKS = 8;

    public function __invoke(Project $project, ProjectProgress $progress, ProjectDeployer $deployer): JsonResponse
    {
        if ($project->status === ProjectStatus::Building
            && $project->deployment_build_id !== null
            && Cache::add("project-follow-asked:{$project->id}", true, self::SECONDS_BETWEEN_LOOKS)) {
            try {
                $deployer->follow($project);
            } catch (Throwable $exception) {
                // The diary is still worth answering with; the queue reports its own failures.
                report($exception);
            }
        }

        return new JsonResponse([
            'estado' => $project->refresh()->status->value,
            'entradas' => $progress->entries($project),
        ]);
    }
}
