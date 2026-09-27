<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Filament\Resources\ProjectMilestoneResource\Pages;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\TeamMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectMilestoneResource extends Resource
{
    use ScopesToTeamMember;

    protected static ?string $model = ProjectMilestone::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->relationship('project', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
                ...static::milestoneFields(),
            ]);
    }

    /**
     * Fields shared by this resource and the project page's Milestones tab.
     *
     * @return array<Forms\Components\Component>
     */
    public static function milestoneFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('phase')
                ->options(Project::PHASES)
                ->helperText('While this is the current milestone, the project shows this status.'),
            Forms\Components\TextInput::make('sequence')
                ->required()
                ->numeric()
                ->default(fn ($livewire): int => $livewire instanceof RelationManager
                    ? (int) $livewire->getOwnerRecord()->milestones()->max('sequence') + 1
                    : 1)
                ->helperText('Order of this milestone within the project (1 = first).'),
            Forms\Components\TextInput::make('billing_percent')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
            Forms\Components\DatePicker::make('target_date'),
            Forms\Components\DatePicker::make('completed_at'),
            Forms\Components\Select::make('status')
                ->options(ProjectMilestone::STATUSES)
                ->required()
                ->default('pending')
                ->helperText('Moves to In Progress automatically when a task starts. Mark it Completed once the work is approved.'),
        ];
    }

    /**
     * @return array<Tables\Columns\Column>
     */
    public static function milestoneColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('sequence')
                ->label('#')
                ->sortable(),
            Tables\Columns\TextColumn::make('title')
                ->searchable(),
            Tables\Columns\TextColumn::make('phase')
                ->badge()
                ->formatStateUsing(fn (string $state): string => Project::PHASES[$state] ?? $state)
                ->placeholder('—'),
            Tables\Columns\ViewColumn::make('progress')
                ->view('filament.components.progress-bar')
                ->sortable(),
            Tables\Columns\TextColumn::make('tasks_count')
                ->label('Tasks')
                ->counts('tasks'),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ProjectMilestone::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'completed' => 'success',
                    'in_progress' => 'warning',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('billing_percent')
                ->label('Billing')
                ->suffix('%')
                ->sortable(),
            Tables\Columns\TextColumn::make('target_date')
                ->date()
                ->sortable()
                ->color(fn (ProjectMilestone $record): ?string => $record->status !== 'completed' && $record->target_date?->isPast() ? 'danger' : null),
            Tables\Columns\TextColumn::make('completed_at')
                ->date()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function markCompleteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('markComplete')
            ->label('Mark complete')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (ProjectMilestone $record): bool => $record->isReadyToComplete())
            ->requiresConfirmation()
            ->modalHeading('Mark milestone complete?')
            ->modalDescription(fn (ProjectMilestone $record): string => $record->billing_percent > 0
                ? "All tasks are done. Completing it makes its {$record->billing_percent}% billing due."
                : 'All tasks are done.')
            ->action(fn (ProjectMilestone $record) => $record->update(['status' => 'completed']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.title')
                    ->searchable()
                    ->sortable(),
                ...static::milestoneColumns(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->defaultSort('sequence')
            ->actions([
                static::markCompleteAction(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectMilestones::route('/'),
            'create' => Pages\CreateProjectMilestone::route('/create'),
            'edit' => Pages\EditProjectMilestone::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToTeamMember(
            parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]),
            fn (Builder $query, TeamMember $teamMember) => $query->whereHas('project', fn (Builder $query) => $query->visibleToTeamMember($teamMember)),
        );
    }
}
