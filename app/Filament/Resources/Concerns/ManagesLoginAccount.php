<?php

namespace App\Filament\Resources\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates the owning user account alongside a profile record
 * (team member, client, student) from a form using LoginAccountSection.
 */
trait ManagesLoginAccount
{
    /**
     * The profile field whose value becomes the user's account name.
     */
    abstract protected function accountNameField(): string;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $user = User::create([
                ...$data['user'],
                'name' => $data[$this->accountNameField()],
            ]);

            unset($data['user']);

            return static::getModel()::create([...$data, 'user_id' => $user->getKey()]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->user->update([
                ...$data['user'],
                'name' => $data[$this->accountNameField()],
            ]);

            unset($data['user']);

            $record->update($data);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillLoginAccount(array $data): array
    {
        $data['user'] = $this->record->user->only(['phone', 'email']);

        return $data;
    }
}
