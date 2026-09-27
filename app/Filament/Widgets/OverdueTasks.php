<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueTasks extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $teamMember = Filament::getCurrentPanel()?->getId() === 'team' ? auth()->user()?->teamMember : null;

        return $table
            ->heading($teamMember ? 'My overdue tasks' : 'Overdue tasks')
            ->query(
                Task::query()
                    ->overdue()
                    ->with(['project', 'milestone', 'assignee'])
                    ->when($teamMember, fn ($query) => $query->involving($teamMember))
                    ->oldest('due_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('project.title')->placeholder('—'),
                Tables\Columns\TextColumn::make('milestone.title')->label('Milestone')->placeholder('—'),
                Tables\Columns\TextColumn::make('assignee.fullname')->label('Assignee')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('Due')
                    ->since()
                    ->color('danger'),
            ])
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('No overdue tasks')
            ->paginated([5, 10, 25]);
    }
}
