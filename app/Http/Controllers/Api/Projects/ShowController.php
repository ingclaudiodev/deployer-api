<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;

class ShowController extends Controller
{
    public function __invoke(Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }
}
