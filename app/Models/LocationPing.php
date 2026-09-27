<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every location a team member's browser reported, matched or not, kept as an audit trail.
 */
#[Fillable(['team_member_id', 'recorded_at', 'latitude', 'longitude', 'accuracy', 'matched', 'project_id', 'office_location_id', 'distance'])]
class LocationPing extends Model
{
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'matched' => 'boolean',
        ];
    }

    public function mapUrl(): string
    {
        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }

    public function placeName(): ?string
    {
        return $this->project?->title ?? $this->officeLocation?->name;
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
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
