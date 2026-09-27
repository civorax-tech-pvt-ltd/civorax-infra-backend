<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One team member's presence on one (business-timezone) day. GPS attendance is created the
 * first time the member is seen inside a project site's or an office's radius that day.
 */
#[Fillable(['team_member_id', 'date', 'first_seen_at', 'last_seen_at', 'source', 'note', 'recorded_by'])]
class Attendance extends Model
{
    /**
     * Readings less precise than this (in metres) are logged but never mark attendance.
     */
    public const MAX_ACCURACY = 500;

    protected static function booted(): void
    {
        $refreshMonthlySummary = function (Attendance $attendance): void {
            AttendanceMonthlySummary::rebuildFor($attendance->team_member_id, $attendance->date);

            $originalDate = $attendance->getOriginal('date');

            if ($originalDate !== null && ! Carbon::parse($originalDate)->isSameMonth($attendance->date)) {
                AttendanceMonthlySummary::rebuildFor($attendance->team_member_id, $originalDate);
            }
        };

        static::saved($refreshMonthlySummary);
        static::deleted($refreshMonthlySummary);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function businessToday(): string
    {
        return now(config('app.business_timezone'))->toDateString();
    }

    /**
     * Great-circle distance between two coordinates, in metres.
     */
    public static function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Log a location from a team member's browser and mark attendance when it is inside
     * an office or one of their project sites.
     *
     * @return array{matched: bool, place: ?string, distance: ?int, message: string}
     */
    public static function recordLocation(TeamMember $member, float $latitude, float $longitude, float $accuracy, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $match = $accuracy <= self::MAX_ACCURACY ? self::nearestPlace($member, $latitude, $longitude) : null;

        LocationPing::create([
            'team_member_id' => $member->id,
            'recorded_at' => $at,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => (int) round($accuracy),
            'matched' => $match !== null,
            'project_id' => ($match['project'] ?? null)?->id,
            'office_location_id' => ($match['office'] ?? null)?->id,
            'distance' => $match['distance'] ?? null,
        ]);

        if ($match === null) {
            return [
                'matched' => false,
                'place' => null,
                'distance' => null,
                'message' => $accuracy > self::MAX_ACCURACY
                    ? 'Location too imprecise (±'.round($accuracy).' m). Turn on GPS / precise location.'
                    : 'Not at an office or one of your project sites.',
            ];
        }

        DB::transaction(function () use ($member, $match, $at): void {
            $date = $at->copy()->timezone(config('app.business_timezone'))->toDateString();

            $attendance = static::query()->where('team_member_id', $member->id)->whereDate('date', $date)->first()
                ?? static::query()->create([
                    'team_member_id' => $member->id,
                    'date' => $date,
                    'first_seen_at' => $at,
                    'last_seen_at' => $at,
                    'source' => 'gps',
                ]);

            $attendance->forceFill([
                'first_seen_at' => $attendance->first_seen_at->min($at),
                'last_seen_at' => $attendance->last_seen_at->max($at),
            ])->save();

            $visit = $attendance->visits()
                ->where('project_id', $match['project']?->id)
                ->where('office_location_id', $match['office']?->id)
                ->first();

            if ($visit === null) {
                $attendance->visits()->create([
                    'project_id' => $match['project']?->id,
                    'office_location_id' => $match['office']?->id,
                    'first_seen_at' => $at,
                    'last_seen_at' => $at,
                    'closest_distance' => $match['distance'],
                ]);

                AttendanceMonthlySummary::rebuildFor($member->id, $attendance->date);

                return;
            }

            $visit->forceFill([
                'last_seen_at' => $visit->last_seen_at->max($at),
                'closest_distance' => min($visit->closest_distance, $match['distance']),
                'pings' => $visit->pings + 1,
            ])->save();
        });

        return [
            'matched' => true,
            'place' => $match['name'],
            'distance' => $match['distance'],
            'message' => "Present at {$match['name']}",
        ];
    }

    /**
     * The closest office or active assigned project site whose radius contains the point.
     *
     * @return array{name: string, distance: int, project: ?Project, office: ?OfficeLocation}|null
     */
    protected static function nearestPlace(TeamMember $member, float $latitude, float $longitude): ?array
    {
        $places = collect();

        foreach (OfficeLocation::query()->where('is_active', true)->get() as $office) {
            $places->push(['name' => $office->name, 'radius' => $office->geofence_radius, 'lat' => $office->latitude, 'lng' => $office->longitude, 'project' => null, 'office' => $office]);
        }

        $projects = Project::query()
            ->visibleToTeamMember($member)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('status', '!=', 'completed')
            ->get();

        foreach ($projects as $project) {
            $places->push(['name' => $project->title, 'radius' => $project->geofence_radius, 'lat' => (float) $project->latitude, 'lng' => (float) $project->longitude, 'project' => $project, 'office' => null]);
        }

        return $places
            ->map(fn (array $place): array => [...$place, 'distance' => self::distanceInMeters($latitude, $longitude, $place['lat'], $place['lng'])])
            ->filter(fn (array $place): bool => $place['distance'] <= $place['radius'])
            ->sortBy('distance')
            ->map(fn (array $place): array => [
                'name' => $place['name'],
                'distance' => $place['distance'],
                'project' => $place['project'],
                'office' => $place['office'],
            ])
            ->first();
    }

    /**
     * Every location the member reported during this attendance's business day, newest first.
     *
     * @return HasMany<LocationPing, TeamMember>
     */
    public function dayPings(): HasMany
    {
        $timezone = config('app.business_timezone');
        $start = Carbon::parse($this->date->toDateString(), $timezone)->startOfDay()->utc();

        return $this->teamMember->hasMany(LocationPing::class)
            ->whereBetween('recorded_at', [$start, $start->copy()->addDay()->subSecond()])
            ->with(['project', 'officeLocation'])
            ->latest('recorded_at');
    }

    public function latestPing(): ?LocationPing
    {
        return $this->dayPings()->first();
    }

    public function hoursOnDuty(): float
    {
        return round($this->first_seen_at->diffInMinutes($this->last_seen_at) / 60, 1);
    }

    public function placesSummary(): string
    {
        return $this->visits->map->placeName()->unique()->implode(', ') ?: '—';
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(AttendanceVisit::class)->orderBy('first_seen_at');
    }
}
