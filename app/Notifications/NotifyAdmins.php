<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Notifies every admin (super_admin) about something that needs their attention.
 */
class NotifyAdmins
{
    public static function send(string $title, string $body, string $url, string $icon, string $color = 'info'): void
    {
        Alert::send(User::role('super_admin')->get(), $title, $body, $url, $icon, $color);
    }
}
