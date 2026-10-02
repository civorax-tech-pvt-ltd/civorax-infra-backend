<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Notifications\PortalPasswordReset;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot password" for phone-number logins: the person types the phone number (or email) they sign in with,
 * and the reset link goes to the email on their account. The answer is the same whether or not an account
 * matched, so the page can't be used to find out who has an account.
 */
class RequestPasswordResetByPhone extends RequestPasswordReset
{
    public function form(Form $form): Form
    {
        return $form->schema([
            $this->getLoginFormComponent(),
        ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Phone number or email')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus()
            ->helperText('The phone number you sign in with. The reset link is sent to the email on your account.');
    }

    public function request(): void
    {
        try {
            $this->rateLimit(3);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $login = trim((string) $this->form->getState()['login']);
        $user = $this->findUser($login);

        // Only accounts of this portal that have an email can get a link.
        if ($user !== null && filled($user->email) && $user->canAccessPanel(Filament::getCurrentPanel())) {
            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $user->email],
                function (User $user, string $token): void {
                    $user->notify(new PortalPasswordReset(
                        Filament::getResetPasswordUrl($token, $user),
                        Filament::getCurrentPanel()->getId(),
                    ));

                    event(new PasswordResetLinkSent($user));
                },
            );
        }

        Notification::make()
            ->title('Check your email')
            ->body('If this phone number or email belongs to an account here with an email address, we have sent a link to set a new password. No email on your account? Ask the CivoraX office to reset it for you.')
            ->success()
            ->persistent()
            ->send();

        $this->form->fill();
    }

    protected function findUser(string $login): ?User
    {
        if ($login === '') {
            return null;
        }

        if (str_contains($login, '@')) {
            return User::query()->whereRaw('LOWER(email) = ?', [Str::lower($login)])->first();
        }

        // Phone numbers as typed, or without spaces/dashes and a +977 prefix.
        $digits = preg_replace('/\D/', '', $login);
        $local = Str::startsWith($digits, '977') && strlen($digits) > 10 ? substr($digits, 3) : $digits;

        return User::query()->whereIn('phone', array_unique([$login, $digits, $local]))->first();
    }
}
