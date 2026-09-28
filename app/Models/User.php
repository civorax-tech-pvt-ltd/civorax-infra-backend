<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token', 'current_session_id'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function teamMember(): HasOne
    {
        return $this->hasOne(TeamMember::class);
    }

    /**
     * Site approval powers (approve_site_records, pay_labour_wages): super admins, or roles granted them.
     */
    public function hasSitePower(string $permission): bool
    {
        return $this->hasRole('super_admin') || $this->can($permission);
    }

    /**
     * Users holding a site power, e.g. everyone who should review a submitted muster roll.
     *
     * @return Collection<int, User>
     */
    public static function withSitePower(string $permission): Collection
    {
        return static::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
                ->orWhereHas('roles.permissions', fn ($query) => $query->where('name', $permission))
                ->orWhereHas('permissions', fn ($query) => $query->where('name', $permission)))
            ->get();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('super_admin'),
            'team' => $this->teamMember !== null && $this->roles()->exists(),
            'client' => $this->client !== null,
            'student' => $this->student !== null,
            default => false,
        };
    }
}
