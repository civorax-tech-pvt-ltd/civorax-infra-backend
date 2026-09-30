<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A team member's salary share charged to a project for one month, from GPS attendance:
 * salary × days at the project ÷ days present. A day at two sites counts half to each; office days
 * (and days at no project) stay company overhead. Saved permanently, because daily detail is pruned.
 */
#[Fillable(['project_id', 'team_member_id', 'month', 'project_days', 'present_days', 'monthly_salary', 'amount'])]
class StaffCostAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'project_days' => 'decimal:1',
            'monthly_salary' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * (Re)calculate every salaried team member's allocation for one month (calendar month, business time).
     *
     * @return int allocation rows written
     */
    public static function allocateMonth(CarbonInterface|string $month): int
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $written = 0;

        TeamMember::query()->whereNotNull('monthly_salary')->where('monthly_salary', '>', 0)->each(function (TeamMember $member) use ($start, $end, &$written): void {
            $days = Attendance::query()
                ->where('team_member_id', $member->id)
                ->whereDate('date', '>=', $start)
                ->whereDate('date', '<=', $end)
                ->with('visits')
                ->get();

            // Don't wipe a month whose daily detail was already pruned.
            if ($days->isEmpty()) {
                return;
            }

            $projectDays = [];

            foreach ($days as $day) {
                $projects = $day->visits->pluck('project_id')->filter()->unique();

                foreach ($projects as $projectId) {
                    $projectDays[$projectId] = ($projectDays[$projectId] ?? 0) + 1 / $projects->count();
                }
            }

            DB::transaction(function () use ($member, $start, $days, $projectDays, &$written): void {
                static::query()->where('team_member_id', $member->id)->whereDate('month', $start)->delete();

                foreach ($projectDays as $projectId => $count) {
                    static::query()->create([
                        'project_id' => $projectId,
                        'team_member_id' => $member->id,
                        'month' => $start->toDateString(),
                        'project_days' => round($count, 1),
                        'present_days' => $days->count(),
                        'monthly_salary' => $member->monthly_salary,
                        'amount' => round((float) $member->monthly_salary * $count / $days->count(), 2),
                    ]);
                    $written++;
                }
            });
        });

        return $written;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }
}
