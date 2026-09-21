<?php

namespace App\Filament\Client\Auth;

use App\Filament\Auth\PhoneRegister;
use App\Models\Client;
use App\Models\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

class ClientRegister extends PhoneRegister
{
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getPhoneFormComponent(),
                        $this->getEmailFormComponent(),
                        TextInput::make('company_name')
                            ->label('Company name (optional)')
                            ->maxLength(255),
                        Select::make('client_type_id')
                            ->label('Client type')
                            ->options(fn () => ClientType::query()->where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function handleRegistration(array $data): Model
    {
        $user = $this->getUserModel()::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
        ]);

        Client::create([
            'user_id' => $user->id,
            'company_name' => $data['company_name'] ?? null,
            'contact_person' => $data['name'],
            'client_type_id' => $data['client_type_id'],
            'contact' => $data['phone'],
            'address' => $data['address'],
            'created_by' => null,
        ]);

        return $user;
    }
}
