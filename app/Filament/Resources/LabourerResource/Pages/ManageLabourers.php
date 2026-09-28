<?php

namespace App\Filament\Resources\LabourerResource\Pages;

use App\Filament\Resources\LabourerResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLabourers extends ManageRecords
{
    protected static string $resource = LabourerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
