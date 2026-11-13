<?php

namespace App\Http\Controllers\Api\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryConnectionResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The GitHub and GitLab accounts the signed-in user connected.
 */
class ConnectionIndexController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        return RepositoryConnectionResource::collection($user->repositoryConnections()->orderBy('provider')->get());
    }
}
