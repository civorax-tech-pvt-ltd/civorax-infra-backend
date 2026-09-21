<?php

namespace App\Filament\Resources\TeamMemberResource\Pages;

use App\Filament\Resources\TeamMemberResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateTeamMember extends CreateRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected function afterCreate(): void
    {
        $roles = Role::query()->whereIn('id', $this->data['roles'] ?? [])->get();
        $this->record->user->syncRoles($roles);
    }
}
