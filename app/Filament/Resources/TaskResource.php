<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\Task;
use App\Models\TeamMember;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TaskResource extends Resource
{
    use ScopesToTeamMember;

    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->relationship('project', 'title')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('milestone_id', null)),
                Forms\Components\Select::make('milestone_id')
                    ->label('Milestone')
                    ->relationship(
                        'milestone',
                        'title',
                        fn (Builder $query, Get $get) => $query
                            ->where('project_id', $get('project_id'))
                            ->orderBy('sequence'),
                    )
                    ->disabled(fn (Get $get): bool => blank($get('project_id')))
                    ->helperText('Completing tasks moves this milestone\'s progress bar.'),
                ...static::taskFields(),
            ]);
    }

    /**
     * Fields shared by this resource and the project page's Tasks tab.
     *
     * @return array<Forms\Components\Component>
     */
    public static function taskFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('assignee_id')
                ->label('Assignee')
                ->relationship('assignee', 'fullname', fn (Builder $query) => static::limitTeamMemberOptions($query))
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set(
                    'members',
                    array_values(array_diff($get('members') ?? [], [$state])),
                ))
                ->helperText('The person responsible for finishing this task.'),
            Forms\Components\Select::make('members')
                ->label('Helpers')
                ->relationship(
                    'members',
                    'fullname',
                    fn (Builder $query, Get $get) => static::limitTeamMemberOptions($query)->when(
                        $get('assignee_id'),
                        fn (Builder $query, $assigneeId) => $query->whereKeyNot($assigneeId),
                    ),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->saveRelationshipsUsing(fn (Task $record, $state) => static::syncTeamMembers(
                    $record->members(),
                    $state,
                    exclude: [$record->assignee_id],
                ))
                ->helperText('Other team members helping. They also see this task in the team panel.'),
            Forms\Components\Select::make('status')
                ->options(Task::STATUSES)
                ->required()
                ->default('pending'),
            Forms\Components\DateTimePicker::make('due_at')
                ->label('Due')
                ->seconds(false)
                ->helperText('Deadline. After this the task shows as Overdue until it is Completed.'),
            Forms\Components\TextInput::make('weight')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(10)
                ->default(1)
                ->required()
                ->helperText('How much this task counts toward milestone progress (1–10).'),
            Forms\Components\Textarea::make('description')
                ->columnSpanFull(),
            Forms\Components\Hidden::make('created_by')
                ->default(fn () => auth()->id()),
        ];
    }

    /**
     * @return array<Tables\Columns\Column>
     */
    public static function taskColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('title')
                ->searchable(),
            Tables\Columns\TextColumn::make('assignee.fullname')
                ->label('Assignee')
                ->placeholder('Unassigned')
                ->sortable(),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state): string => Task::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'completed' => 'success',
                    'in_progress' => 'warning',
                    'blocked' => 'danger',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('due_at')
                ->label('Due')
                ->dateTime()
                ->sortable()
                ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null)
                ->description(fn (Task $record): ?string => $record->isOverdue() ? 'Overdue' : null),
            Tables\Columns\TextColumn::make('weight')
                ->numeric()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('milestone.title')
                    ->label('Milestone')
                    ->placeholder('—'),
                ...static::taskColumns(),
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
            ->groups([
                Tables\Grouping\Group::make('project.title')->label('Project'),
                Tables\Grouping\Group::make('status')->label('Status'),
            ])
            ->defaultGroup(Filament::getCurrentPanel()?->getId() === 'team' ? 'project.title' : null)
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(Task::STATUSES),
                Tables\Filters\SelectFilter::make('project')
                    ->relationship('project', 'title'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
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
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToTeamMember(
            parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]),
            fn (Builder $query, TeamMember $teamMember) => $query->involving($teamMember),
        );
    }

    public static function getNavigationLabel(): string
    {
        return Filament::getCurrentPanel()?->getId() === 'team' ? 'My Tasks' : 'Tasks';
    }
}
