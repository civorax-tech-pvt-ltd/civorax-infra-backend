<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('milestone_id')
                ->label('Milestone')
                ->relationship(
                    'milestone',
                    'title',
                    fn (Builder $query) => $query
                        ->where('project_id', $this->getOwnerRecord()->getKey())
                        ->orderBy('sequence'),
                ),
            ...TaskResource::taskFields(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns(TaskResource::taskColumns())
            ->defaultGroup(
                Group::make('milestone.title')
                    ->label('Milestone')
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query
                        ->leftJoin('project_milestones as group_milestones', 'group_milestones.id', '=', 'tasks.milestone_id')
                        ->orderBy('group_milestones.sequence', $direction)
                        ->select('tasks.*')),
            )
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(Task::STATUSES),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
