<?php

namespace App\Filament\Resources\BoqMasterItemResource\Pages;

use App\Filament\Resources\BoqMasterItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBoqMasterItems extends ManageRecords
{
    protected static string $resource = BoqMasterItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New library item'),
        ];
    }
}
