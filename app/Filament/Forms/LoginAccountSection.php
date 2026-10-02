<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

/**
 * Login fields (phone, email, password) for the user account that owns a profile record.
 * State lives under `user`; pages persist it with the ManagesLoginAccount trait.
 */
class LoginAccountSection
{
    public static function make(string $nameSource): Section
    {
        return Section::make('Login account')
            ->description("Signs in with this phone number and password. The {$nameSource} is used as the account name.")
            ->statePath('user')
            ->columns(2)
            ->schema([
                TextInput::make('phone')
                    ->label('Phone number')
                    ->tel()
                    ->required()
                    ->maxLength(255)
                    ->unique('users', 'phone', ignorable: fn (?Model $record) => $record?->user),
                TextInput::make('email')
                    ->label('Email address (optional)')
                    ->helperText('Needed for "Forgot password": the reset link is emailed here.')
                    ->email()
                    ->maxLength(255)
                    ->unique('users', 'email', ignorable: fn (?Model $record) => $record?->user),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
            ]);
    }
}
