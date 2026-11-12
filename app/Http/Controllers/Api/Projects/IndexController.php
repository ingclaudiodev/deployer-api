<?php

namespace App\Http\Controllers\Api\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The projects of the signed-in user, most recently changed first.
 */
class IndexController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        return ProjectResource::collection($user->projects()->latest('updated_at')->get());
    }
}
