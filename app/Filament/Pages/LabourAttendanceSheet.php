<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\LabourerResource;
use App\Models\LabourAttendance;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The supervisor's daily register: pick the site and day, tap P / H / A for each labourer, save.
 */
class LabourAttendanceSheet extends Page
{
    use SiteAccess;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Labour Attendance';

    protected static ?string $title = 'Labour Attendance';

    protected static ?string $slug = 'labour-attendance';

    protected static string $view = 'filament.pages.labour-attendance-sheet';

    /**
     * @var array{project_id?: int|string|null, date?: string|null}
     */
    public array $filters = [];

    /**
     * Labourer id => ['status' => present|half_day|absent|null, 'overtime_hours' => float].
     *
     * @var array<int|string, array{status: string|null, overtime_hours: float|int|string|null}>
     */
    public array $rows = [];

    public static function canAccess(): bool
    {
        return static::canUseSite();
    }

    public function mount(): void
    {
        $this->filters = [
            'project_id' => request()->integer('project') ?: static::siteProjectQuery()->active()->orderBy('title')->value('id'),
            'date' => request('date', now(config('app.business_timezone'))->toDateString()),
        ];

        $this->form->fill($this->filters);
        $this->loadRows();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadRows()),
                Forms\Components\DatePicker::make('date')
                    ->required()
                    ->maxDate(now(config('app.business_timezone'))->toDateString())
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadRows())
                    ->helperText(fn (?string $state): ?string => $state ? 'B.S. '.MusterRoll::bsDate($state) : null),
            ]);
    }

    public function project(): ?Project
    {
        return filled($this->filters['project_id'] ?? null) ? Project::find($this->filters['project_id']) : null;
    }

    /**
     * Labourers shown: everyone already marked that day, plus whoever worked this site in the last 30 days.
     */
    public function loadRows(): void
    {
        $this->rows = [];
        $project = $this->project();
        $date = $this->filters['date'] ?? null;

        if ($project === null || blank($date)) {
            return;
        }

        $marked = LabourAttendance::query()
            ->where('project_id', $project->getKey())
            ->whereDate('date', $date)
            ->get()
            ->keyBy('labourer_id');

        $recent = LabourAttendance::query()
            ->where('project_id', $project->getKey())
            ->whereDate('date', '>=', Carbon::parse($date)->subDays(30))
            ->whereHas('labourer', fn ($query) => $query->active())
            ->distinct()
            ->pluck('labourer_id');

        foreach ($marked->keys()->merge($recent)->unique() as $labourerId) {
            $this->rows[$labourerId] = [
                'status' => $marked[$labourerId]->status ?? null,
                'overtime_hours' => (float) ($marked[$labourerId]->overtime_hours ?? 0),
            ];
        }
    }

    /**
     * @return Collection<int, Labourer>
     */
    public function labourers(): Collection
    {
        return Labourer::withTrashed()
            ->with('contractor')
            ->whereKey(array_keys($this->rows))
            ->get()
            ->sortBy(fn (Labourer $labourer): string => $labourer->work_type.$labourer->name)
            ->values();
    }

    public function lockedBy(): ?MusterRoll
    {
        $project = $this->project();

        return $project && filled($this->filters['date'] ?? null)
            ? MusterRoll::lockedFor($project->getKey(), $this->filters['date'])
            : null;
    }

    public function setStatus(int|string $labourerId, ?string $status): void
    {
        if (! isset($this->rows[$labourerId]) || $this->lockedBy()) {
            return;
        }

        $this->rows[$labourerId]['status'] = $this->rows[$labourerId]['status'] === $status ? null : $status;
    }

    public function markAllPresent(): void
    {
        foreach ($this->rows as $labourerId => $row) {
            $this->rows[$labourerId]['status'] ??= 'present';
        }
    }

    public function removeRow(int|string $labourerId): void
    {
        if (($this->rows[$labourerId]['status'] ?? null) === null) {
            unset($this->rows[$labourerId]);
        }
    }

    /**
     * @return array{present: float, headcount: int, wages: float}
     */
    public function summary(): array
    {
        $labourers = Labourer::withTrashed()->whereKey(array_keys($this->rows))->pluck('daily_wage', 'id');
        $existingRates = $this->project()
            ? LabourAttendance::query()->where('project_id', $this->project()->getKey())->whereDate('date', $this->filters['date'])->pluck('wage_rate', 'labourer_id')
            : collect();

        $present = 0.0;
        $headcount = 0;
        $wages = 0.0;

        foreach ($this->rows as $labourerId => $row) {
            $entry = new LabourAttendance([
                'status' => $row['status'],
                'overtime_hours' => (float) ($row['overtime_hours'] ?: 0),
                'wage_rate' => $existingRates[$labourerId] ?? $labourers[$labourerId] ?? 0,
            ]);

            if ($row['status'] && $row['status'] !== 'absent') {
                $headcount++;
            }

            $present += $entry->dayFactor();
            $wages += $entry->wage();
        }

        return ['present' => $present, 'headcount' => $headcount, 'wages' => round($wages, 2)];
    }

    public function save(): void
    {
        $this->form->validate();
        $project = $this->project();

        try {
            $count = LabourAttendance::saveDay($project, $this->filters['date'], $this->rows, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->title('Not saved')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();

            return;
        }

        Notification::make()
            ->title("Saved attendance for {$count} ".str('labourer')->plural($count))
            ->body($project->title.' · '.Carbon::parse($this->filters['date'])->format('D, M j').' (B.S. '.MusterRoll::bsDate($this->filters['date']).')')
            ->success()
            ->send();

        $this->loadRows();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addLabourer')
                ->label('Add labourer')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->disabled(fn (): bool => $this->project() === null || $this->lockedBy() !== null)
                ->form([
                    Forms\Components\Select::make('labourer_ids')
                        ->label('Labourers')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Labourer::query()
                            ->active()
                            ->whereKeyNot(array_keys($this->rows))
                            ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('father_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Labourer $labourer): array => [$labourer->id => $labourer->selectLabel()])
                            ->all())
                        ->options(fn (): array => Labourer::query()->active()->whereKeyNot(array_keys($this->rows))->orderBy('name')->limit(50)->get()
                            ->mapWithKeys(fn (Labourer $labourer): array => [$labourer->id => $labourer->selectLabel()])->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    foreach ($data['labourer_ids'] as $labourerId) {
                        $this->rows[$labourerId] ??= ['status' => 'present', 'overtime_hours' => 0];
                    }
                }),
            // A create action (not a plain action) so the form has a model: the naike picker loads its options from the relationship.
            CreateAction::make('newLabourer')
                ->label('New labourer')
                ->icon('heroicon-o-plus')
                ->model(Labourer::class)
                ->modalHeading('New labourer')
                ->createAnother(false)
                ->disabled(fn (): bool => $this->project() === null || $this->lockedBy() !== null)
                ->form(LabourerResource::labourerFields())
                ->successNotificationTitle(fn (Labourer $record): string => "{$record->name} added to the register")
                ->after(function (Labourer $record): void {
                    $this->rows[$record->id] = ['status' => 'present', 'overtime_hours' => 0];
                }),
        ];
    }
}
