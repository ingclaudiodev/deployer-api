<?php

namespace App\Http\Controllers\Api\Repositories;

use App\Enums\RepositoryProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

/**
 * Forgets the tokens of a connected account. Projects keep the repository they
 * were created with; the user connects again to pick another.
 */
class DisconnectController extends Controller
{
    public function __invoke(RepositoryProvider $provider, #[CurrentUser] User $user): Response
    {
        $user->repositoryConnections()->where('provider', $provider)->delete();

        return response()->noContent();
    }
}
