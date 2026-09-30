<?php

namespace App\Filament\Resources\PettyCashClaimResource\Pages;

use App\Filament\Resources\PettyCashClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePettyCashClaims extends ManageRecords
{
    protected static string $resource = PettyCashClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New claim')
                ->mutateFormDataUsing(fn (array $data): array => [...$data, 'status' => 'pending', 'claimed_by' => auth()->id()]),
        ];
    }
}
