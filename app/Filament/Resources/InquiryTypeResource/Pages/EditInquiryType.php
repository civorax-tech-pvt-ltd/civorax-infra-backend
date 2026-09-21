<?php

namespace App\Filament\Resources\InquiryTypeResource\Pages;

use App\Filament\Resources\InquiryTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInquiryType extends EditRecord
{
    protected static string $resource = InquiryTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
