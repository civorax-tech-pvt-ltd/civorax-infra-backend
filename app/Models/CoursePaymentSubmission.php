<?php

namespace App\Models;

use App\Filament\Resources\CoursePaymentSubmissionResource;
use App\Notifications\Alerts;
use App\Notifications\NotifyAdmins;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['enrollment_id', 'amount', 'transaction_reference', 'screenshot_path'])]
class CoursePaymentSubmission extends Model
{
    use LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::created(function (CoursePaymentSubmission $submission): void {
            NotifyAdmins::send(
                title: 'Course payment submitted: NPR '.number_format((float) $submission->amount, 2),
                body: "{$submission->enrollment?->student?->fullname} — {$submission->enrollment?->course?->title}. Ref: {$submission->transaction_reference}.",
                url: CoursePaymentSubmissionResource::getUrl(panel: 'admin'),
                icon: 'heroicon-o-academic-cap',
                color: 'warning',
            );
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approve(User $reviewer, ?string $note = null): CoursePayment
    {
        $payment = CoursePayment::create([
            'enrollment_id' => $this->enrollment_id,
            'amount' => $this->amount,
            'received_at' => now(),
            'remark' => "Verified from submission — ref: {$this->transaction_reference}",
            'recorded_by' => $reviewer->id,
        ]);

        $this->forceFill([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        Alerts::coursePaymentVerified($payment);

        return $payment;
    }

    public function reject(User $reviewer, ?string $note = null): void
    {
        $this->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        Alerts::coursePaymentRejected($this);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
