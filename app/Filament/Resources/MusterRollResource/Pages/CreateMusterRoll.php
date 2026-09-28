<?php

namespace App\Filament\Resources\MusterRollResource\Pages;

use App\Filament\Resources\MusterRollResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMusterRoll extends CreateRecord
{
    protected static string $resource = MusterRollResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => 'draft', 'prepared_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
