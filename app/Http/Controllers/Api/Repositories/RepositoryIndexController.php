<?php

namespace App\Http\Controllers\Api\Repositories;

use App\Enums\RepositoryProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Repositories the connected account can reach, most recently pushed first.
 * 409 with `reconectar` when there is no usable connection.
 */
class RepositoryIndexController extends Controller
{
    public function __invoke(Request $request, RepositoryProvider $provider, #[CurrentUser] User $user, RepositoryConnector $connector): JsonResponse
    {
        $validated = $request->validate(['pagina' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return new JsonResponse($connector->repositories($user, $provider, (int) ($validated['pagina'] ?? 1)));
    }
}
