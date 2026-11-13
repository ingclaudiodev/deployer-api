<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Http\JsonResponse;

/**
 * Copies the deployment files of the project to a branch of its repository,
 * for owners who want them in their own history. 404 while there is no
 * analysis to copy; 409 with `reconectar` without a usable account at the host.
 */
class DeploymentBranchController extends Controller
{
    public function __invoke(Project $project, ProjectAnalyzer $analyzer): JsonResponse
    {
        $branch = $analyzer->publishBranch($project);

        abort_if($branch === null, 404);

        return new JsonResponse(['rama' => $branch], 201);
    }
}
