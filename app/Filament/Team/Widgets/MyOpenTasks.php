<?php

namespace App\Filament\Team\Widgets;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MyOpenTasks extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->teamMember !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('My open tasks')
            ->description('Soonest deadline first. Update the status as you work — project progress follows automatically.')
            ->query(
                Task::query()
                    ->involving(auth()->user()->teamMember)
                    ->where('status', '!=', 'completed')
                    ->with(['project', 'milestone'])
                    ->orderByRaw('due_at IS NULL')
                    ->orderBy('due_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->weight('bold')
                    ->description(fn (Task $record): ?string => $record->milestone?->title),
                Tables\Columns\TextColumn::make('project.title')->placeholder('—'),
                ...array_filter(TaskResource::taskColumns(), fn ($column): bool => in_array($column->getName(), ['status', 'due_at'], true)),
            ])
            ->actions([
                Tables\Actions\Action::make('start')
                    ->icon('heroicon-m-play')
                    ->color('warning')
                    ->visible(fn (Task $record): bool => $record->status === 'pending' && auth()->user()->can('update', $record))
                    ->action(fn (Task $record) => $record->update(['status' => 'in_progress'])),
                Tables\Actions\Action::make('done')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn (Task $record): bool => auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Task $record): string => "Mark \"{$record->title}\" as completed?")
                    ->action(fn (Task $record) => $record->update(['status' => 'completed'])),
            ])
            ->emptyStateHeading('No open tasks')
            ->emptyStateDescription('You are all caught up.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }
}
