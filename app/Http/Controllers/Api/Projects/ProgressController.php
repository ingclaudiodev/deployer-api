<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectProgress;
use Illuminate\Http\JsonResponse;

/**
 * What the last analysis or deployment of the project did so far, line by line.
 * The same lines are pushed live as `.project.progress`; this is how a screen
 * that just opened catches up.
 */
class ProgressController extends Controller
{
    public function __invoke(Project $project, ProjectProgress $progress): JsonResponse
    {
        return new JsonResponse(['entradas' => $progress->entries($project)]);
    }
}
