<?php

namespace App\Filament\Resources\BoqMeasurementResource\Pages;

use App\Filament\Resources\BoqMeasurementResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBoqMeasurements extends ManageRecords
{
    protected static string $resource = BoqMeasurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Record measurement')
                ->mutateFormDataUsing(fn (array $data): array => [...$data, 'status' => 'pending', 'entered_by' => auth()->id()]),
        ];
    }
}
