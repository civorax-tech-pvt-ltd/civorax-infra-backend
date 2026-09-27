<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['course_id', 'starts_at', 'ends_at', 'status', 'meeting_url', 'recording_url', 'note', 'created_by'])]
class ClassSession extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::created(function (ClassSession $session): void {
            if ($session->status !== 'cancelled' && $session->starts_at?->isFuture()) {
                Alerts::classScheduled($session);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
