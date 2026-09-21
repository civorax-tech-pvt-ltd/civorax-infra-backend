<?php

namespace App\Filament\Resources\ClientTypeResource\Pages;

use App\Filament\Resources\ClientTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClientTypes extends ListRecords
{
    protected static string $resource = ClientTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
