<?php

namespace App\Models;

use App\Filament\Resources\ProjectPaymentSubmissionResource;
use App\Notifications\Alerts;
use App\Notifications\NotifyAdmins;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A payment a client reports from their portal; it becomes a Payment once staff verify it.
 */
#[Fillable(['project_id', 'milestone_id', 'amount', 'transaction_reference', 'screenshot_path', 'submitted_by'])]
class ProjectPaymentSubmission extends Model
{
    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending verification',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::created(function (ProjectPaymentSubmission $submission): void {
            NotifyAdmins::send(
                title: 'Payment submitted: NPR '.number_format((float) $submission->amount, 2),
                body: "{$submission->project->client?->contact_person} — {$submission->project->title}. Ref: {$submission->transaction_reference}. Verify before approving.",
                url: ProjectPaymentSubmissionResource::getUrl(panel: 'admin'),
                icon: 'heroicon-o-banknotes',
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

    /**
     * Record the verified payment. Fails if the amount no longer fits the project's balance.
     *
     * @throws ValidationException
     */
    public function approve(User $reviewer, ?string $note = null): Payment
    {
        $error = Payment::amountError($this->project, (float) $this->amount)
            ?? Payment::milestoneAmountError($this->milestone, (float) $this->amount);

        if ($error !== null) {
            throw ValidationException::withMessages(['amount' => $error]);
        }

        return DB::transaction(function () use ($reviewer, $note): Payment {
            $payment = Payment::create([
                'project_id' => $this->project_id,
                'milestone_id' => $this->milestone_id,
                'amount' => $this->amount,
                'received_at' => $this->created_at ?? now(),
                'remark' => "Verified client submission — ref: {$this->transaction_reference}",
                'recorded_by' => $reviewer->id,
            ]);

            $this->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'payment_id' => $payment->id,
            ])->save();

            return $payment;
        });
    }

    public function reject(User $reviewer, string $note): void
    {
        $this->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        Alerts::paymentSubmissionRejected($this);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
