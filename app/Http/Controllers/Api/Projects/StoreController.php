<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Registers a project. Nothing is built yet: it starts as "not deployed" and
 * the deploy flow takes it from there.
 */
class StoreController extends Controller
{
    public function __invoke(StoreProjectRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $user->projects()->create($request->modelAttributes());

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }
}
