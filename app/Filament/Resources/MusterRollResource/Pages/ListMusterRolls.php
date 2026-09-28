<?php

namespace App\Filament\Resources\MusterRollResource\Pages;

use App\Filament\Resources\MusterRollResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMusterRolls extends ListRecords
{
    protected static string $resource = MusterRollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New muster roll'),
        ];
    }
}
