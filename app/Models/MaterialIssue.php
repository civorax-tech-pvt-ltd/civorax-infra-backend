<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Key material issued from site stock to one BOQ item, valued at the project's weighted-average purchase rate.
 * It only allocates material cost already in the project ledger to an item; it never adds project cost.
 */
#[Fillable(['project_id', 'boq_item_id', 'key_material_id', 'issued_on', 'quantity', 'rate', 'value', 'note', 'status', 'issued_by', 'approved_by', 'approved_at'])]
class MaterialIssue extends Model
{
    use LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::saving(function (MaterialIssue $issue): void {
            if ($issue->boq_item_id && ($item = BoqItem::find($issue->boq_item_id))) {
                $issue->project_id = $item->project_id;
            }

            // The rate is the weighted-average purchase rate when the issue is recorded, unless one was given.
            if ($issue->rate === null) {
                $issue->rate = (new ProjectMaterialReport(Project::findOrFail($issue->project_id)))->averageRate((int) $issue->key_material_id) ?? 0;
            }

            $issue->value = round((float) $issue->quantity * (float) $issue->rate, 2);
        });

        static::created(function (MaterialIssue $issue): void {
            if ($issue->status === 'pending') {
                Alerts::materialIssueSubmitted($issue);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'value' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    public static function canBeReviewedBy(?User $user, self $issue): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_boq_measurements')
            && ((int) $issue->issued_by !== (int) $user->getKey() || $user->hasRole('super_admin'));
    }

    public function approve(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages(['status' => (int) $this->issued_by === (int) $by->getKey()
                ? 'You recorded this issue, so someone else must approve it.'
                : 'You are not allowed to approve material issues.']);
        }

        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now()])->save();

        Alerts::materialIssueApproved($this);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(KeyMaterial::class, 'key_material_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
