<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'fullname', 'address', 'contact', 'inquiry_type_id', 'contact_channel',
    'referred_by', 'message', 'status', 'handled_by', 'created_by', 'resolved_at',
])]
class Inquiry extends Model
{
    use LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::created(fn (Inquiry $inquiry) => Alerts::newInquiry($inquiry));
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function inquiryType(): BelongsTo
    {
        return $this->belongsTo(InquiryType::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
