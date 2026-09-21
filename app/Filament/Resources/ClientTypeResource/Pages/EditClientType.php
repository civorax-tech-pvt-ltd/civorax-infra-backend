<?php

namespace App\Filament\Resources\ClientTypeResource\Pages;

use App\Filament\Resources\ClientTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClientType extends EditRecord
{
    protected static string $resource = ClientTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
