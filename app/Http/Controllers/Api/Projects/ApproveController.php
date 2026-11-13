<?php

namespace App\Http\Controllers\Api\Projects;

use App\Exceptions\Projects\DeploymentException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Jobs\DeployProject;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\Deployment\ProjectDeployer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Validation\ValidationException;

/**
 * The owner signs the deployment of the last analysis. From here on Deployer
 * builds and deploys exactly the files that were signed. 422 when there is
 * nothing waiting for approval.
 */
class ApproveController extends Controller
{
    public function __invoke(Project $project, #[CurrentUser] User $user, ProjectDeployer $deployer): ProjectResource
    {
        try {
            $project = $deployer->approve($project, $user);
        } catch (DeploymentException) {
            throw ValidationException::withMessages([
                'estado' => __('This project has no deployment waiting for approval.'),
            ]);
        }

        DeployProject::dispatch($project);

        return new ProjectResource($project);
    }
}
