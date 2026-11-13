<?php

namespace App\Http\Controllers\Api\Repositories;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Http\Controllers\Controller;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the host sends the browser back after the user answered. It carries no
 * bearer token: the state says whose connection it is. Whatever happens, the
 * browser ends on the client's form, which reads the outcome from the query.
 */
class CallbackController extends Controller
{
    public function __invoke(Request $request, RepositoryProvider $provider, RepositoryConnector $connector): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($code) || ! is_string($state)) {
            // The user said no at the host, or the link was opened by hand.
            return $this->backToClient($provider, connected: false);
        }

        try {
            $connector->complete($provider, $code, $state, route('repositories.callback', ['provider' => $provider->value]));
        } catch (RepositoryHostException $exception) {
            report($exception);

            return $this->backToClient($provider, connected: false);
        }

        return $this->backToClient($provider, connected: true);
    }

    private function backToClient(RepositoryProvider $provider, bool $connected): RedirectResponse
    {
        /** @var string $frontend */
        $frontend = config('app.frontend_url');
        /** @var string $path */
        $path = config('services.repositories.frontend_path');

        return redirect()->away(rtrim($frontend, '/').$path.'?'.http_build_query([
            'conexion' => $provider->value,
            'resultado' => $connected ? 'ok' : 'error',
        ]));
    }
}
