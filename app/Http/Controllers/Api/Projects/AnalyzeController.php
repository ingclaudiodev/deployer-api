<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeProjectRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectProgress;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Asks for the analysis of the project's repository. It runs in a queue: the
 * answer is 202 and the project reaches "pending approval" when it is done.
 * 409 with `reconectar` when the owner has no usable account at the host.
 */
class AnalyzeController extends Controller
{
    public function __invoke(Project $project, #[CurrentUser] User $user, RepositoryConnector $connector, ProjectProgress $progress): JsonResponse
    {
        // Checked here, where the user can be told, and not minutes later in the queue.
        if ($connector->repository($user, $project->repo_provider, $project->repo_full_name) === null) {
            throw ValidationException::withMessages([
                'repositorio.nombreCompleto' => __('Your connected account cannot reach this repository.'),
            ]);
        }

        $progress->begin($project, ProjectProgress::ANALYSIS, 'Análisis en cola: esperando un trabajador libre…');

        AnalyzeProjectRepository::dispatch($project);

        return new JsonResponse(['estado' => 'en_cola'], 202);
    }
}
