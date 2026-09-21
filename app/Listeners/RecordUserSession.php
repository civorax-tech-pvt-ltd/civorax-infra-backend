<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

class RecordUserSession
{
    public function handle(Login $event): void
    {
        $token = (string) Str::random(60);

        // Stored in the session PAYLOAD (not the session ID), since Filament's
        // login flow calls session()->regenerate() right after this event fires,
        // which changes the session ID but preserves the payload.
        session(['auth_device_token' => $token]);

        $event->user->forceFill([
            'current_session_id' => $token,
        ])->saveQuietly();
    }
}
