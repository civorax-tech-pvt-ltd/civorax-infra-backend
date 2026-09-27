<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Sends a bell notification (and, where enabled, a desktop pop-up) to users of any panel.
 * The person who caused the event is never notified about their own action.
 */
class Alert
{
    /**
     * @param  User|iterable<User|null>|null  $recipients
     */
    public static function send(User|iterable|null $recipients, string $title, string $body, ?string $url, string $icon, string $color = 'info'): void
    {
        $users = Collection::wrap($recipients instanceof User ? [$recipients] : ($recipients ?? []))
            ->filter()
            ->unique('id')
            ->reject(fn (User $user): bool => $user->is(auth()->user()));

        if ($users->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor($color)
            ->actions($url === null ? [] : [
                Action::make('open')
                    ->label('Open')
                    ->url($url)
                    ->markAsRead(),
            ]);

        // Filament queues database notifications by default; send now so it works
        // without a queue worker (typical on shared hosting).
        NotificationFacade::sendNow($users, $notification->toDatabase());
    }
}
