<?php

namespace App\Filament\Auth;

use App\Filament\Auth\Concerns\LogsOutForeignPanelSession;
use App\Models\User;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Register as BaseRegister;

abstract class PhoneRegister extends BaseRegister
{
    use LogsOutForeignPanelSession;

    public function mount(): void
    {
        $this->logoutIfWrongPanel();

        parent::mount();
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Phone number')
            ->tel()
            ->required()
            ->unique(User::class)
            ->maxLength(20);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email address')
            ->email()
            ->maxLength(255)
            ->unique(User::class);
    }
}
