<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One deployment file of the last analysis, for its owner to review. 404 for a
 * path the analysis does not list.
 */
class DeploymentFileController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectAnalyzer $analyzer): JsonResponse
    {
        /** @var array{ruta: string} $validated */
        $validated = $request->validate(['ruta' => ['required', 'string', 'max:255']]);

        $content = $analyzer->file($project, $validated['ruta']);

        abort_if($content === null, 404);

        return new JsonResponse(['ruta' => $validated['ruta'], 'contenido' => $content]);
    }
}
