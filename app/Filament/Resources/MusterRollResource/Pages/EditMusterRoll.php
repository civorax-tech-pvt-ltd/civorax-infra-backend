<?php

namespace App\Filament\Resources\MusterRollResource\Pages;

use App\Filament\Resources\MusterRollResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMusterRoll extends EditRecord
{
    protected static string $resource = MusterRollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            MusterRollResource::deleteAction(Actions\DeleteAction::make()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
