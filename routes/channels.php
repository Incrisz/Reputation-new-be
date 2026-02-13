<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Public user-specific channels are used for audit status notifications.
| If you later add token/session auth, convert this to private channels.
|
*/

Broadcast::channel('audit.user.{userId}', function ($user = null, int $userId) {
    return $user && (int) $user->id === $userId;
});
