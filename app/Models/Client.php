<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['user_id', 'company_name', 'contact_person', 'client_type_id', 'contact', 'address', 'created_by'])]
class Client extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * Columns a client picker searches, so same-named clients can be found by phone, company or address.
     *
     * @var list<string>
     */
    public const SEARCH_COLUMNS = ['contact_person', 'company_name', 'contact', 'address'];

    /**
     * "Name — phone · company · address", to tell same-named clients apart in pickers.
     */
    public function selectLabel(): string
    {
        $details = array_filter([$this->contact, $this->company_name, $this->address]);

        return $details === []
            ? $this->contact_person
            : $this->contact_person.' — '.implode(' · ', $details);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientType(): BelongsTo
    {
        return $this->belongsTo(ClientType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
