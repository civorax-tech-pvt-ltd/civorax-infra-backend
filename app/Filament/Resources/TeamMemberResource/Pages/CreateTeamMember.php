<?php

namespace App\Filament\Resources\TeamMemberResource\Pages;

use App\Filament\Resources\Concerns\ManagesLoginAccount;
use App\Filament\Resources\TeamMemberResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateTeamMember extends CreateRecord
{
    use ManagesLoginAccount;

    protected static string $resource = TeamMemberResource::class;

    protected function accountNameField(): string
    {
        return 'fullname';
    }

    protected function afterCreate(): void
    {
        $roles = Role::query()->whereIn('id', $this->data['roles'] ?? [])->get();
        $this->record->user->syncRoles($roles);
    }
}
