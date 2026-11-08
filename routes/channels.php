<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Each signed-in user owns one private channel: the welcome card and, later,
| the live status of their deployments go through it. The SPA subscribes with
| its Passport bearer token, so the callback must resolve the user through the
| `api` guard: the default (`web`, session) would never find anyone here.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
}, ['guards' => ['api']]);
