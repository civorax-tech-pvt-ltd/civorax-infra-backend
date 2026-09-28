<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LabourAttendance;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\SiteReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The offline-capable site app: a single page that keeps working without internet
 * and sends its saved attendance and daily reports when the phone is back online.
 */
class SiteAppController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorizeSiteUser($request->user());

        return view('site.app');
    }

    /**
     * Everything the app needs to work offline: sites, labourers and the last week's attendance.
     */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSiteUser($user);

        $today = now(config('app.business_timezone'));
        $projects = Project::query()->siteAccessibleBy($user)->active()->orderBy('title')->get(['id', 'title', 'site_address']);

        $attendance = LabourAttendance::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->whereDate('date', '>=', $today->copy()->subDays(30)->toDateString())
            ->get();

        return response()->json([
            'csrf' => csrf_token(),
            'user' => $user->name,
            'today' => $today->toDateString(),
            'today_bs' => MusterRoll::bsDate($today),
            'synced_at' => now()->toIso8601String(),
            'hours_per_day' => config('site.hours_per_day'),
            'weather' => SiteReport::WEATHER,
            'work_types' => Labourer::WORK_TYPES,
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'title' => $project->title,
                'address' => $project->site_address,
                // Labourers who worked here in the last 30 days are listed first on the attendance screen.
                'crew' => $attendance->where('project_id', $project->id)->pluck('labourer_id')->unique()->values(),
                'locked_until' => MusterRoll::query()->where('project_id', $project->id)->whereIn('status', ['submitted', 'approved'])->max('ends_on'),
            ])->values(),
            'labourers' => Labourer::query()->active()->orderBy('name')->get()->map(fn (Labourer $labourer): array => [
                'id' => $labourer->id,
                'name' => $labourer->name,
                'father' => $labourer->father_name,
                'type' => $labourer->workTypeLabel(),
                'wage' => (float) $labourer->daily_wage,
            ]),
            'attendance' => $attendance
                ->where('date', '>=', $today->copy()->subDays(7)->startOfDay())
                ->groupBy(fn (LabourAttendance $entry): string => $entry->project_id.'|'.$entry->date->toDateString())
                ->map(fn ($entries) => $entries->mapWithKeys(fn (LabourAttendance $entry): array => [
                    $entry->labourer_id => ['status' => $entry->status, 'overtime_hours' => (float) $entry->overtime_hours],
                ])),
        ]);
    }

    public function storeAttendance(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSiteUser($user);

        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:'.now(config('app.business_timezone'))->toDateString()],
            'rows' => ['required', 'array'],
            'rows.*.status' => ['nullable', Rule::in(array_keys(LabourAttendance::STATUSES))],
            'rows.*.overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:16'],
        ]);

        $project = $this->siteProject($user, $data['project_id']);
        $count = LabourAttendance::saveDay($project, $data['date'], $data['rows'], $user);

        return response()->json(['message' => "Saved attendance for {$count} labourers at {$project->title}."]);
    }

    public function storeReport(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSiteUser($user);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'project_id' => ['required', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:'.now(config('app.business_timezone'))->toDateString()],
            'weather' => ['nullable', Rule::in(array_keys(SiteReport::WEATHER))],
            'work_done' => ['required', 'string', 'max:5000'],
            'issues' => ['nullable', 'string', 'max:3000'],
            'next_day_plan' => ['nullable', 'string', 'max:3000'],
            'visitors' => ['nullable', 'string', 'max:3000'],
            'manpower' => ['nullable', 'array'],
            'manpower.*.trade' => ['required', 'string', 'max:100'],
            'manpower.*.count' => ['required', 'integer', 'min:0', 'max:999'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photos' => ['nullable', 'array', 'max:15'],
            'photos.*' => ['image', 'max:8192'],
        ]);

        // A retry after a dropped connection must not create a second report.
        if ($existing = SiteReport::query()->where('client_uuid', $data['client_uuid'])->first()) {
            return response()->json(['message' => 'Already received.', 'id' => $existing->id]);
        }

        $project = $this->siteProject($user, $data['project_id']);
        $date = Carbon::parse($data['date'])->toDateString();

        if (SiteReport::query()->where('project_id', $project->id)->whereDate('date', $date)->exists()) {
            throw ValidationException::withMessages([
                'date' => "{$project->title} already has a report for {$date}. Add to it from the team panel instead.",
            ]);
        }

        $photos = collect($request->file('photos', []))->map(fn ($photo): string => $photo->store('site-reports', 'public'))->all();

        $report = DB::transaction(function () use ($data, $project, $date, $photos, $user): SiteReport {
            $report = SiteReport::create([
                ...collect($data)->only(['client_uuid', 'weather', 'work_done', 'issues', 'next_day_plan', 'visitors', 'manpower', 'latitude', 'longitude'])->all(),
                'project_id' => $project->id,
                'date' => $date,
                'photos' => $photos,
                'status' => 'draft',
                'submitted_by' => $user->id,
            ]);

            $report->submit($user);

            return $report;
        });

        return response()->json(['message' => "Report for {$project->title} submitted.", 'id' => $report->id], 201);
    }

    protected function authorizeSiteUser(?User $user): void
    {
        abort_unless($user !== null && ($user->teamMember !== null || $user->hasRole('super_admin')), 403);
    }

    protected function siteProject(User $user, int $projectId): Project
    {
        $project = Project::query()->siteAccessibleBy($user)->find($projectId);

        if ($project === null) {
            throw ValidationException::withMessages(['project_id' => 'You are not on this project\'s team.']);
        }

        return $project;
    }
}
