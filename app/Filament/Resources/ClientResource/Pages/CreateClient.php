<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\Concerns\ManagesLoginAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use ManagesLoginAccount;

    protected static string $resource = ClientResource::class;

    protected function accountNameField(): string
    {
        return 'contact_person';
    }
}
