<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels (V2 real-time notifications)
|--------------------------------------------------------------------------
| Before the browser may subscribe to a private channel, Laravel Echo calls
| POST /broadcasting/auth (session + CSRF protected). Laravel runs the callback
| below; returning false means HTTP 403 and the subscription is refused.
|
| Rule: "users.5" can only be joined by user 5, so nobody can listen to
| another user's notifications. Inactive users are refused as well.
*/

Broadcast::channel('users.{userId}', function (User $user, $userId) {
    return $user->id === (int) $userId && $user->is_active;
});
