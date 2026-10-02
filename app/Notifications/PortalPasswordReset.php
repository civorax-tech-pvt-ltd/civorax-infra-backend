<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "Forgot password" email. Sent straight away (not queued), so it arrives even where no queue worker runs.
 */
class PortalPasswordReset extends Notification
{
    public function __construct(public string $url, public string $portal) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your CivoraX password')
            ->greeting('Namaste '.($notifiable->name ?? '').',')
            ->line("We received a request to reset the password of your CivoraX {$this->portal} account.")
            ->action('Set a new password', $this->url)
            ->line("This link works for {$minutes} minutes and can be used once.")
            ->line('If you did not ask for this, ignore this email: your password stays the same.')
            ->salutation('CivoraX Infra');
    }
}
