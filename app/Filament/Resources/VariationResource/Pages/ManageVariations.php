<?php

namespace App\Filament\Resources\VariationResource\Pages;

use App\Filament\Resources\VariationResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageVariations extends ManageRecords
{
    protected static string $resource = VariationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New variation')
                ->mutateFormDataUsing(fn (array $data): array => [...$data, 'status' => 'pending', 'entered_by' => auth()->id()]),
        ];
    }
}
