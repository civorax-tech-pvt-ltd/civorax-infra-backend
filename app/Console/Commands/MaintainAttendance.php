<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\LocationPing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MaintainAttendance extends Command
{
    protected $signature = 'attendance:maintain';

    protected $description = 'Refresh monthly attendance summaries and delete daily detail older than the retention period';

    public function handle(): int
    {
        $cutoff = AttendanceMonthlySummary::retentionCutoff();

        AttendanceMonthlySummary::rebuildMonth(Attendance::businessToday());
        AttendanceMonthlySummary::rebuildMonth(Carbon::parse(Attendance::businessToday())->subMonthNoOverflow());

        // Summarise each expiring month one last time, then drop its detail.
        $expiringMonths = Attendance::query()
            ->whereDate('date', '<', $cutoff->toDateString())
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->copy()->startOfMonth()->toDateString())
            ->unique();

        foreach ($expiringMonths as $month) {
            AttendanceMonthlySummary::rebuildMonth($month, beforePruning: true);
        }

        // Query-builder deletes skip model events, so the frozen summaries are left untouched.
        $days = Attendance::query()->whereDate('date', '<', $cutoff->toDateString())->delete();
        $pings = LocationPing::query()
            ->where('recorded_at', '<', Carbon::parse($cutoff->toDateString(), config('app.business_timezone'))->utc())
            ->delete();

        $this->info("Kept detail from {$cutoff->format('F Y')} onward. Deleted {$days} attendance days and {$pings} GPS readings; monthly summaries kept.");

        return self::SUCCESS;
    }
}
