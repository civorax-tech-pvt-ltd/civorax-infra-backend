<?php

namespace App\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Turns new bell notifications into desktop (operating-system) notifications while any
 * panel page is open, including in a background tab.
 */
class DesktopAlerts extends Component
{
    /**
     * Unread notifications newer than $since (ISO time), oldest first.
     *
     * @return array{now: string, alerts: list<array{id: string, title: string, body: string, url: ?string}>}
     */
    public function check(?string $since = null): array
    {
        $user = auth()->user();
        $now = now()->toIso8601String();

        if ($user === null || $since === null) {
            return ['now' => $now, 'alerts' => []];
        }

        $alerts = $user->unreadNotifications()
            ->where('created_at', '>', Carbon::parse($since))
            ->oldest()
            ->limit(5)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => strip_tags((string) ($notification->data['title'] ?? 'CivoraX')),
                'body' => strip_tags((string) ($notification->data['body'] ?? '')),
                'url' => $notification->data['actions'][0]['url'] ?? null,
            ])
            ->all();

        return ['now' => $now, 'alerts' => $alerts];
    }

    public function render(): View
    {
        return view('livewire.desktop-alerts');
    }
}
