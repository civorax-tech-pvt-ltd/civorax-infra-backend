<?php

namespace App\Filament\Resources\TeamMemberResource\Pages;

use App\Filament\Resources\Concerns\ManagesLoginAccount;
use App\Filament\Resources\TeamMemberResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditTeamMember extends EditRecord
{
    use ManagesLoginAccount;

    protected static string $resource = TeamMemberResource::class;

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
        return 'fullname';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['national_id_path'] = $this->record->national_id_path;
        $data['bank_account_number'] = $this->record->bank_account_number;
        $data['roles'] = $this->record->user->roles->pluck('id')->toArray();

        return $this->fillLoginAccount($data);
    }

    protected function afterSave(): void
    {
        $roles = Role::query()->whereIn('id', $this->data['roles'] ?? [])->get();
        $this->record->user->syncRoles($roles);
    }
}
