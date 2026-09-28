<?php

namespace App\Filament\Resources\WagePaymentResource\Pages;

use App\Filament\Resources\WagePaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageWagePayments extends ManageRecords
{
    protected static string $resource = WagePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Record payment / advance'),
        ];
    }
}
