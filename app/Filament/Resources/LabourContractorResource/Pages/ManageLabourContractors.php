<?php

namespace App\Filament\Resources\LabourContractorResource\Pages;

use App\Filament\Resources\LabourContractorResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLabourContractors extends ManageRecords
{
    protected static string $resource = LabourContractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
