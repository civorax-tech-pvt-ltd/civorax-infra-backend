<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A professional in the company's contact directory, optionally with a GPS location for finding people near a site.
 */
#[Fillable([
    'fullname', 'professional_type', 'contact', 'alt_contact', 'email', 'address', 'latitude', 'longitude',
    'years_of_experience', 'photo_path', 'notes', 'is_available', 'added_by',
])]
class Professional extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'civil_engineer' => 'Civil engineer',
        'structural_engineer' => 'Structural engineer',
        'architect' => 'Architect',
        'interior_designer' => 'Interior designer',
        'overseer' => 'Overseer / site supervisor',
        'surveyor' => 'Surveyor',
        'draftsman' => 'Draftsman / AutoCAD',
        'contractor' => 'Contractor (Thekedar)',
        'mason' => 'Mason (Dakarmi)',
        'carpenter' => 'Carpenter (Sikarmi)',
        'electrician' => 'Electrician',
        'plumber' => 'Plumber',
        'painter' => 'Painter',
        'welder' => 'Welder / fabricator',
        'tile_fitter' => 'Tile fitter',
        'bar_bender' => 'Bar bender',
        'other' => 'Other',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_available' => true];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'years_of_experience' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->professional_type] ?? (string) $this->professional_type;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function mapUrl(): ?string
    {
        return $this->hasLocation() ? 'https://www.google.com/maps?q='.(float) $this->latitude.','.(float) $this->longitude : null;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /**
     * Distance in km from a point, or null without a location.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        return $this->hasLocation()
            ? round(Attendance::distanceInMeters($latitude, $longitude, (float) $this->latitude, (float) $this->longitude) / 1000, 1)
            : null;
    }

    /**
     * Nearest first, using a flat-earth approximation that plain SQL (MySQL and SQLite) can sort by;
     * accurate enough for "who is close to this site". People without a location go last.
     */
    public function scopeNearest(Builder $query, float $latitude, float $longitude): void
    {
        $lngScale = cos(deg2rad($latitude)) ** 2;

        $query->orderByRaw('CASE WHEN latitude IS NULL OR longitude IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?) * ?)', [$latitude, $latitude, $longitude, $longitude, $lngScale]);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
