<?php

namespace App\Filament\Resources\KeyMaterialResource\Pages;

use App\Filament\Resources\KeyMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageKeyMaterials extends ManageRecords
{
    protected static string $resource = KeyMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
