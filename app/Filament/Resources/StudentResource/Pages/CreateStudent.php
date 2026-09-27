<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Concerns\ManagesLoginAccount;
use App\Filament\Resources\StudentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudent extends CreateRecord
{
    use ManagesLoginAccount;

    protected static string $resource = StudentResource::class;

    protected function accountNameField(): string
    {
        return 'fullname';
    }
}
