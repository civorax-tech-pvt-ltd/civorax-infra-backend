<?php

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkOrders extends ManageRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New work order')
                ->mutateFormDataUsing(fn (array $data): array => [...$data, 'status' => 'pending', 'entered_by' => auth()->id()]),
        ];
    }
}
