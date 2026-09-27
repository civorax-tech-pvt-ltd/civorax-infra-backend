<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['project_id', 'milestone_id', 'amount', 'received_at', 'remark', 'recorded_by'])]
class Payment extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Payment $payment) => Alerts::paymentReceived($payment));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Why an amount cannot be recorded against a project, or null when it can.
     * Pass the payment being edited so its current amount is not counted twice.
     */
    public static function amountError(?Project $project, float $amount, ?Payment $ignore = null, float $alsoReserved = 0): ?string
    {
        if ($project === null) {
            return null;
        }

        if ($project->fee === null || (float) $project->fee <= 0) {
            return 'This project has no agreed fee yet. Set the fee (or accept a quotation) before recording payments.';
        }

        if ($amount <= 0) {
            return 'The amount must be more than zero.';
        }

        $alreadyPaid = (float) $project->payments()
            ->when($ignore?->exists, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->sum('amount');
        $remaining = round((float) $project->fee - $alreadyPaid - $alsoReserved, 2);

        if ($amount > $remaining) {
            return $remaining <= 0
                ? 'Nothing is left to pay on this project.'
                : 'The amount is more than what is left to pay (NPR '.number_format($remaining, 2).').';
        }

        return null;
    }

    /**
     * Why an amount cannot be tagged to a milestone (more than its unpaid share), or null when it can.
     */
    public static function milestoneAmountError(?ProjectMilestone $milestone, float $amount, ?Payment $ignore = null, float $alsoReserved = 0): ?string
    {
        $left = $milestone?->amountLeft($ignore);

        if ($left === null) {
            return null;
        }

        $left = max(0, round($left - $alsoReserved, 2));

        if ($amount > $left) {
            return $left <= 0
                ? "The \"{$milestone->title}\" share is already paid. Leave the milestone empty to record an advance."
                : "More than the unpaid \"{$milestone->title}\" share (NPR ".number_format($left, 2).'). Leave the milestone empty to record an advance.';
        }

        return null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
