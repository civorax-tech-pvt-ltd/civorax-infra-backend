<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Pages\ManageCertificateSettings;
use App\Filament\Resources\CertificateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('settings')
                ->label('Letterhead & signatures')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(ManageCertificateSettings::getUrl()),
            Actions\CreateAction::make()->label('Issue certificate'),
        ];
    }
}
