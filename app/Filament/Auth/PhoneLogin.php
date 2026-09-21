<?php

namespace App\Filament\Auth;

use App\Filament\Auth\Concerns\LogsOutForeignPanelSession;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login as BaseLogin;

class PhoneLogin extends BaseLogin
{
    use LogsOutForeignPanelSession;

    public function mount(): void
    {
        $this->logoutIfWrongPanel();

        parent::mount();

        if ($notice = session('single_session_notice')) {
            Notification::make()
                ->title('Signed out')
                ->body($notice)
                ->warning()
                ->send();
        }
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Phone number')
            ->tel()
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'phone' => $data['phone'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'data.phone' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
