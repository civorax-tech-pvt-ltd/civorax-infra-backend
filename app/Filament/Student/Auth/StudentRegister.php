<?php

namespace App\Filament\Student\Auth;

use App\Filament\Auth\PhoneRegister;
use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

class StudentRegister extends PhoneRegister
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
                        DatePicker::make('dob')
                            ->label('Date of birth')
                            ->required(),
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255),
                        TagsInput::make('academic_qualification')
                            ->label('Academic qualifications'),
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

        Student::create([
            'user_id' => $user->id,
            'fullname' => $data['name'],
            'dob' => $data['dob'],
            'contact' => $data['phone'],
            'address' => $data['address'],
            'academic_qualification' => $data['academic_qualification'] ?? [],
            'created_by' => null,
        ]);

        return $user;
    }
}
