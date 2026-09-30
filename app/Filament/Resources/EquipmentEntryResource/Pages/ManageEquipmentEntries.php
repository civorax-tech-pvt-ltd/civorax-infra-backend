<?php

namespace App\Filament\Resources\EquipmentEntryResource\Pages;

use App\Filament\Resources\EquipmentEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageEquipmentEntries extends ManageRecords
{
    protected static string $resource = EquipmentEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New entry')
                ->mutateFormDataUsing(fn (array $data): array => [...$data, 'status' => 'pending', 'entered_by' => auth()->id()]),
        ];
    }
}
