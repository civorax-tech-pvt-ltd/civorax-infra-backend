<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'project_id', 'labourer_id', 'date', 'status', 'overtime_hours',
    'wage_rate', 'work_type', 'note', 'marked_by',
])]
class LabourAttendance extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'present' => 'Present',
        'half_day' => 'Half day',
        'absent' => 'Absent',
    ];

    /**
     * Letters used on the muster roll grid.
     *
     * @var array<string, string>
     */
    public const CODES = [
        'present' => 'P',
        'half_day' => 'H',
        'absent' => 'A',
    ];

    protected static function booted(): void
    {
        static::saving(function (LabourAttendance $attendance): void {
            // Rate and trade are copied at the time, so later wage changes never rewrite history.
            if ($attendance->wage_rate === null || $attendance->work_type === null) {
                $labourer = $attendance->labourer;
                $attendance->wage_rate ??= $labourer->daily_wage;
                $attendance->work_type ??= $labourer->work_type;
            }

            $attendance->overtime_hours ??= 0;

            static::ensureNotLocked($attendance->project_id, $attendance->date);

            if ($attendance->isDirty(['project_id', 'date']) && $attendance->getOriginal('project_id')) {
                static::ensureNotLocked($attendance->getOriginal('project_id'), $attendance->getOriginal('date'));
            }
        });

        static::deleting(fn (LabourAttendance $attendance) => static::ensureNotLocked($attendance->project_id, $attendance->date));
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'overtime_hours' => 'decimal:1',
            'wage_rate' => 'decimal:2',
        ];
    }

    /**
     * Attendance inside a submitted or approved muster roll cannot change until that roll is returned.
     */
    public static function ensureNotLocked(int|string|null $projectId, CarbonInterface|string|null $date): void
    {
        if ($projectId === null || $date === null) {
            return;
        }

        $roll = MusterRoll::lockedFor((int) $projectId, $date);

        if ($roll !== null) {
            throw ValidationException::withMessages([
                'date' => "Attendance for this day is locked: the {$roll->label()} muster roll is {$roll->statusLabel()}. Ask an approver to return it first.",
            ]);
        }
    }

    /**
     * Save a whole day of attendance for one site in one go (the marking page and the offline app use this).
     *
     * @param  array<int|string, array{status?: string|null, overtime_hours?: float|int|string|null, note?: string|null}>  $rows  keyed by labourer id; a blank status removes the entry
     * @return int number of labourers marked
     */
    public static function saveDay(Project $project, CarbonInterface|string $date, array $rows, ?User $by = null): int
    {
        $date = Carbon::parse($date)->toDateString();
        static::ensureNotLocked($project->getKey(), $date);

        $elsewhere = static::query()
            ->whereDate('date', $date)
            ->where('project_id', '!=', $project->getKey())
            ->whereIn('labourer_id', array_keys($rows))
            ->with(['labourer', 'project'])
            ->get();

        if ($elsewhere->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => $elsewhere->map(fn (LabourAttendance $entry): string => "{$entry->labourer->name} is already marked at {$entry->project->title} on this day.")->implode(' '),
            ]);
        }

        return DB::transaction(function () use ($project, $date, $rows, $by): int {
            $marked = 0;

            foreach ($rows as $labourerId => $row) {
                $existing = static::query()->where('labourer_id', $labourerId)->whereDate('date', $date)->first();
                $status = $row['status'] ?? null;

                if (blank($status)) {
                    $existing?->delete();

                    continue;
                }

                if (! array_key_exists($status, self::STATUSES)) {
                    throw ValidationException::withMessages(['rows' => "Unknown attendance status \"{$status}\"."]);
                }

                $attendance = $existing ?? new static([
                    'project_id' => $project->getKey(),
                    'labourer_id' => $labourerId,
                    'date' => $date,
                ]);

                $attendance->fill([
                    'status' => $status,
                    'overtime_hours' => $status === 'absent' ? 0 : max(0, min(16, (float) ($row['overtime_hours'] ?? 0))),
                    'note' => $row['note'] ?? $attendance->note,
                    'marked_by' => $by?->getKey() ?? $attendance->marked_by,
                ])->save();

                $marked++;
            }

            return $marked;
        });
    }

    public function dayFactor(): float
    {
        return match ($this->status) {
            'present' => 1.0,
            'half_day' => 0.5,
            default => 0.0,
        };
    }

    public function wage(): float
    {
        $rate = (float) $this->wage_rate;

        return round($rate * $this->dayFactor() + (float) $this->overtime_hours * $rate / max(1, config('site.hours_per_day')), 2);
    }

    public function code(): string
    {
        $code = self::CODES[$this->status] ?? '?';

        return (float) $this->overtime_hours > 0 ? $code.'+'.rtrim(rtrim(number_format((float) $this->overtime_hours, 1), '0'), '.') : $code;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function labourer(): BelongsTo
    {
        return $this->belongsTo(Labourer::class)->withTrashed();
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
