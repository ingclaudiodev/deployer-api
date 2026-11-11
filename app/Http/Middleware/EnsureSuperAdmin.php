<?php

namespace App\Http\Middleware;

use App\Auth\Panel\PanelSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second lock of the panel, behind `auth`: the sign-in already refuses anyone who
 * is not a super administrator, this covers a session whose user was demoted later.
 */
class EnsureSuperAdmin
{
    public function __construct(private readonly PanelSession $panel) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->panel->mayEnter($user), 403);

        return $next($request);
    }
}
