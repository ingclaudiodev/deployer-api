<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Http\JsonResponse;

/**
 * The last analysis of the project as it was stored, with `files` as the paths
 * of its deployment files. 404 while the project was never analysed.
 */
class AnalysisController extends Controller
{
    public function __invoke(Project $project, ProjectAnalyzer $analyzer): JsonResponse
    {
        $analysis = $analyzer->stored($project);

        abort_if($analysis === null, 404);

        return new JsonResponse($analysis);
    }
}
