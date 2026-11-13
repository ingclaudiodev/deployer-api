<?php

namespace App\Http\Controllers\Api\Repositories;

use App\Enums\RepositoryProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Starts connecting an account: answers with the page of the host where the
 * user authorizes Deployer. The client sends the browser there.
 */
class ConnectController extends Controller
{
    public function __invoke(RepositoryProvider $provider, #[CurrentUser] User $user, RepositoryConnector $connector): JsonResponse
    {
        return new JsonResponse([
            'url' => $connector->authorizationUrl($user, $provider, route('repositories.callback', ['provider' => $provider->value])),
        ]);
    }
}
