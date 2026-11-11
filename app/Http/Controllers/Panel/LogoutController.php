<?php

namespace App\Http\Controllers\Panel;

use App\Auth\Panel\PanelSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __invoke(Request $request, PanelSession $panel): RedirectResponse
    {
        $panel->close($request);

        return to_route('home');
    }
}
