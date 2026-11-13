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
 * Branch names of one repository of the connected account.
 */
class BranchIndexController extends Controller
{
    public function __invoke(Request $request, RepositoryProvider $provider, #[CurrentUser] User $user, RepositoryConnector $connector): JsonResponse
    {
        /** @var array{repositorio: string} $validated */
        $validated = $request->validate([
            // Same shape `ProjectRequest` accepts: it goes into the path of the host's API.
            'repositorio' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+$/', 'not_regex:/(^|\/)\.\.?(\/|$)/'],
        ]);

        return new JsonResponse($connector->branches($user, $provider, $validated['repositorio']));
    }
}
