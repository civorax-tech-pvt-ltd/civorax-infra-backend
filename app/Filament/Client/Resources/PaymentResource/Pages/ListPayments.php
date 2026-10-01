<?php

namespace App\Filament\Client\Resources\PaymentResource\Pages;

use App\Filament\Client\Resources\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected ?string $subheading = 'Payments we have received from you. To pay, open the project and use "Make payment".';
}
