<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permanent per-member monthly totals. Daily attendance detail is pruned after a few months;
 * these rows are what remains for payroll and history.
 */
#[Fillable(['team_member_id', 'month', 'present_days', 'gps_days', 'manual_days', 'total_hours', 'place_days'])]
class AttendanceMonthlySummary extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'total_hours' => 'float',
            'place_days' => 'array',
        ];
    }

    /**
     * First month whose daily detail is still kept; everything before it is summary-only.
     */
    public static function retentionCutoff(): Carbon
    {
        return Carbon::parse(Attendance::businessToday())
            ->startOfMonth()
            ->subMonthsNoOverflow(config('attendance.keep_months'));
    }

    /**
     * Recalculate one member's month from their daily attendance. Months before the retention
     * cutoff are frozen (their detail is gone), unless $beforePruning is set by the clean-up.
     */
    public static function rebuildFor(int $teamMemberId, Carbon|string $anyDayInMonth, bool $beforePruning = false): void
    {
        $month = Carbon::parse($anyDayInMonth)->startOfMonth();

        if (! $beforePruning && $month->lt(static::retentionCutoff())) {
            return;
        }

        $days = Attendance::query()
            ->where('team_member_id', $teamMemberId)
            ->whereDate('date', '>=', $month->toDateString())
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->with('visits.project', 'visits.officeLocation')
            ->get();

        $existing = static::query()
            ->where('team_member_id', $teamMemberId)
            ->whereDate('month', $month->toDateString())
            ->first();

        if ($days->isEmpty()) {
            $existing?->delete();

            return;
        }

        $placeDays = $days
            ->flatMap(fn (Attendance $day) => $day->visits->map->placeName()->unique())
            ->countBy()
            ->sortDesc()
            ->all();

        $totals = [
            'present_days' => $days->count(),
            'gps_days' => $days->where('source', 'gps')->count(),
            'manual_days' => $days->where('source', 'manual')->count(),
            'total_hours' => round($days->sum(fn (Attendance $day): float => $day->hoursOnDuty()), 1),
            'place_days' => $placeDays,
        ];

        $existing
            ? $existing->update($totals)
            : static::query()->create(['team_member_id' => $teamMemberId, 'month' => $month->toDateString(), ...$totals]);
    }

    /**
     * Recalculate every member's summary for a month.
     */
    public static function rebuildMonth(Carbon|string $anyDayInMonth, bool $beforePruning = false): void
    {
        $month = Carbon::parse($anyDayInMonth)->startOfMonth();

        $memberIds = Attendance::query()
            ->whereDate('date', '>=', $month->toDateString())
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->distinct()
            ->pluck('team_member_id');

        foreach ($memberIds as $memberId) {
            static::rebuildFor($memberId, $month, $beforePruning);
        }
    }

    public function placesSummary(): string
    {
        return collect($this->place_days ?? [])
            ->map(fn (int $days, string $place): string => "{$place} ({$days}d)")
            ->implode(', ') ?: '—';
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }
}
