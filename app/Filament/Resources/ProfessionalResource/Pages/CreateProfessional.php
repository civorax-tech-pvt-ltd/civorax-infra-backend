<?php

namespace App\Filament\Resources\ProfessionalResource\Pages;

use App\Filament\Resources\ProfessionalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProfessional extends CreateRecord
{
    protected static string $resource = ProfessionalResource::class;

    protected static ?string $title = 'Add professional';

    /**
     * Who added them is always the signed-in user, whatever the form says.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'added_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
