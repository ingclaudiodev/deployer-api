<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;

class UpdateController extends Controller
{
    public function __invoke(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->modelAttributes());

        return new ProjectResource($project);
    }
}
