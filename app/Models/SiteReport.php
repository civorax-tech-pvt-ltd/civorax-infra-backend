<?php

namespace App\Models;

use App\Notifications\Alerts;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The daily site diary: weather, manpower, work done, photos, issues and tomorrow's plan.
 * Approved reports are shown to the client.
 */
#[Fillable([
    'project_id', 'date', 'weather', 'manpower', 'work_done', 'work_items', 'issues',
    'next_day_plan', 'visitors', 'photos', 'latitude', 'longitude', 'status',
    'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'review_note', 'client_uuid',
])]
class SiteReport extends Model
{
    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'returned' => 'Returned',
    ];

    /**
     * @var array<string, string>
     */
    public const WEATHER = [
        'sunny' => 'Sunny',
        'cloudy' => 'Cloudy',
        'light_rain' => 'Light rain',
        'heavy_rain' => 'Heavy rain (work stopped)',
        'cold' => 'Cold / foggy',
        'hot' => 'Very hot',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'manpower' => 'array',
            'work_items' => 'array',
            'photos' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    /**
     * Headcount by trade from the day's labour attendance, e.g. to prefill the manpower table.
     *
     * @return list<array{trade: string, count: int}>
     */
    public static function manpowerFromAttendance(int|string|null $projectId, CarbonInterface|string|null $date): array
    {
        if (blank($projectId) || blank($date)) {
            return [];
        }

        return LabourAttendance::query()
            ->where('project_id', $projectId)
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->where('status', '!=', 'absent')
            ->get()
            ->groupBy('work_type')
            ->map(fn ($entries, string $type): array => ['trade' => Labourer::WORK_TYPES[$type] ?? $type, 'count' => $entries->count()])
            ->values()
            ->all();
    }

    public function totalManpower(): int
    {
        return (int) collect($this->manpower ?? [])->sum(fn (array $row): int => (int) ($row['count'] ?? 0));
    }

    /**
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn (string $path): string => Storage::disk('public')->url($path))->values()->all();
    }

    public function submit(User $by): void
    {
        $this->forceFill(['status' => 'submitted', 'submitted_by' => $by->getKey(), 'submitted_at' => now(), 'review_note' => null])->save();

        Alerts::siteReportSubmitted($this);
    }

    /**
     * Approving publishes the report to the client and completes the tasks it reports as finished.
     */
    public function approve(User $by, ?string $note = null): void
    {
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        $taskIds = collect($this->work_items ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['task_completed'] ?? false) && filled($item['task_id'] ?? null))
            ->pluck('task_id');

        Task::query()
            ->where('project_id', $this->project_id)
            ->whereKey($taskIds)
            ->where('status', '!=', 'completed')
            ->each(fn (Task $task) => $task->update(['status' => 'completed']));

        Alerts::siteReportReviewed($this);
        Alerts::siteReportPublished($this);
    }

    public function returnForChanges(User $by, string $note): void
    {
        $this->forceFill(['status' => 'returned', 'approved_by' => null, 'approved_at' => null, 'review_note' => $note])->save();

        Alerts::siteReportReviewed($this);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
