<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Resources\CertificateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCertificate extends EditRecord
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Print / PDF')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('certificates.show', $this->getRecord()), shouldOpenInNewTab: true),
        ];
    }
}
