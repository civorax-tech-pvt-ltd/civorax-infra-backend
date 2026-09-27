<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\Concerns\ManagesLoginAccount;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    use ManagesLoginAccount;

    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function accountNameField(): string
    {
        return 'contact_person';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillLoginAccount($data);
    }
}
