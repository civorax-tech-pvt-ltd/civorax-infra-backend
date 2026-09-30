<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectCost;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Budget vs actual, projected profit, cash and health for one project.
 */
class ProjectCosts extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string $view = 'filament.pages.project-costs';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(ProjectResource::canViewCosts(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Costs & profit';
    }

    public function getSubheading(): ?string
    {
        return $this->getProject()->title;
    }

    public function getProject(): Project
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('budget')
                ->label('Edit budget')
                ->icon('heroicon-o-pencil-square')
                ->modalWidth('3xl')
                ->modalDescription('What you expect to spend (not the client price). Materials bought on VAT bills include the supplier\'s VAT while the company is PAN-only.')
                ->fillForm(fn (): array => [
                    ...collect(ProjectCost::CATEGORIES)->mapWithKeys(fn (string $label, string $key): array => [
                        "budget_{$key}" => (float) ($this->getProject()->budgets()->where('category', $key)->value('amount') ?? 0) ?: null,
                    ])->all(),
                    'cost_to_finish_override' => $this->getProject()->cost_to_finish_override,
                    'manual_progress' => $this->getProject()->manual_progress,
                ])
                ->form([
                    Forms\Components\Section::make('Cost budget by category')
                        ->columns(2)
                        ->schema(collect(ProjectCost::CATEGORIES)->map(fn (string $label, string $key): Forms\Components\TextInput => Forms\Components\TextInput::make("budget_{$key}")
                            ->label($label)
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rs'))->values()->all()),
                    Forms\Components\Section::make('Forecast')
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('cost_to_finish_override')
                                ->label('Estimated cost to finish (override)')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('Rs')
                                ->helperText('Leave empty to use what is left of the budget after costs so far and committed amounts.'),
                            Forms\Components\TextInput::make('manual_progress')
                                ->label('Manual % work complete')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->helperText('Used only when the project has no BOQ.'),
                        ]),
                ])
                ->action(function (array $data): void {
                    $project = $this->getProject();

                    DB::transaction(function () use ($project, $data): void {
                        foreach (array_keys(ProjectCost::CATEGORIES) as $key) {
                            $amount = (float) ($data["budget_{$key}"] ?? 0);
                            $amount > 0
                                ? $project->budgets()->updateOrCreate(['category' => $key], ['amount' => $amount])
                                : $project->budgets()->where('category', $key)->delete();
                        }

                        $project->update([
                            'cost_to_finish_override' => filled($data['cost_to_finish_override']) ? $data['cost_to_finish_override'] : null,
                            'manual_progress' => filled($data['manual_progress']) ? $data['manual_progress'] : null,
                        ]);
                    });

                    Notification::make()->title('Budget saved')->success()->send();
                }),
            Actions\Action::make('print')
                ->label('Print statement')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('site.projects.costs', $this->getProject()), shouldOpenInNewTab: true),
            Actions\Action::make('back')
                ->label('Project')
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('edit', ['record' => $this->getProject()])),
        ];
    }
}
