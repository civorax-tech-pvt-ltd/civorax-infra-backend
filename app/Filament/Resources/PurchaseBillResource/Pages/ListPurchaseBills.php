<?php

namespace App\Filament\Resources\PurchaseBillResource\Pages;

use App\Filament\Resources\PurchaseBillResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseBills extends ListRecords
{
    protected static string $resource = PurchaseBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Enter bill'),
        ];
    }
}
