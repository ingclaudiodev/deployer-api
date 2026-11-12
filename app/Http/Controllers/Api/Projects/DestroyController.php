<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Response;

class DestroyController extends Controller
{
    public function __invoke(Project $project): Response
    {
        $project->delete();

        return response()->noContent();
    }
}
