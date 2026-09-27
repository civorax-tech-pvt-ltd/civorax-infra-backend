<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place (project site or office) a team member was seen at during one attendance day.
 */
#[Fillable(['attendance_id', 'project_id', 'office_location_id', 'first_seen_at', 'last_seen_at', 'closest_distance', 'pings'])]
class AttendanceVisit extends Model
{
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function placeName(): string
    {
        return $this->project?->title ?? $this->officeLocation?->name ?? '—';
    }

    public function mapUrl(): ?string
    {
        $place = $this->project ?? $this->officeLocation;

        return $place?->latitude === null
            ? null
            : 'https://www.google.com/maps?q='.(float) $place->latitude.','.(float) $place->longitude;
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }
}
