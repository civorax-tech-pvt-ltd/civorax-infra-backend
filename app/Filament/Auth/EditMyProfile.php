<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * "My profile" in every portal (user menu, top right): see your account, fix your name and email,
 * and change your password. The phone number is the login, so only the office changes it.
 * Changing the password signs the account out on other devices (AuthenticateSession).
 */
class EditMyProfile extends EditProfile
{
    public bool $passwordChanged = false;

    public static function getLabel(): string
    {
        return 'My profile';
    }

    public function form(Form $form): Form
    {
        return $form
            ->inlineLabel(false)
            ->schema([
                Section::make('Your account')
                    ->columns(2)
                    ->schema([
                        $this->getNameFormComponent(),
                        TextInput::make('phone')
                            ->label('Phone number (login)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('You sign in with this number. To change it, contact the office.'),
                        $this->getEmailFormComponent()
                            ->required(false)
                            ->helperText('Needed for "Forgot password": the reset link is sent here.'),
                        Placeholder::make('account')
                            ->label('Account')
                            ->content(fn (): string => $this->accountSummary()),
                    ]),
                Section::make('Change password')
                    ->description('Leave empty to keep your current password. After a change, other devices signed in to this account are signed out.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('currentPassword')
                            ->label('Current password')
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->currentPassword()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        $this->getPasswordFormComponent()->label('New password'),
                        $this->getPasswordConfirmationFormComponent()->label('Confirm new password'),
                    ]),
            ]);
    }

    protected function accountSummary(): string
    {
        /** @var User $user */
        $user = $this->getUser();

        $type = match (Filament::getCurrentPanel()?->getId()) {
            'client' => 'Client',
            'student' => 'Student',
            'team' => 'Team · '.($user->getRoleNames()->map(fn (string $role): string => str($role)->replace('_', ' ')->title())->implode(', ') ?: 'Member'),
            default => 'Administrator',
        };

        return $type.' · member since '.$user->created_at?->format('M j, Y');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        // The password itself is never logged; only that it was changed.
        $this->passwordChanged = array_key_exists('password', $data);

        if ($this->passwordChanged) {
            $this->data['currentPassword'] = null;

            activity()
                ->performedOn($record)
                ->causedBy($record)
                ->event('updated')
                ->withProperties(['old' => ['password' => '••••'], 'attributes' => ['password' => 'changed']])
                ->log('Password changed');
        }

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->passwordChanged ? 'Profile saved and password changed' : 'Profile saved';
    }
}
