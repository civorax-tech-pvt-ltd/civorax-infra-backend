<?php

namespace App\Filament\Student\Widgets;

use App\Models\ClassSession;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingClasses extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Upcoming classes')
            ->query(
                ClassSession::query()
                    ->whereIn('course_id', StudentStats::myEnrollments()->pluck('course_id'))
                    ->where('ends_at', '>=', now())
                    ->where('status', '!=', 'cancelled')
                    ->with('course')
                    ->orderBy('starts_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('course.title')->label('Course')->weight('bold'),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('D, M j · g:i A')
                    ->timezone(config('app.business_timezone'))
                    ->description(fn (ClassSession $record): string => $record->starts_at->isPast() ? 'Live now' : $record->starts_at->diffForHumans()),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Ends')
                    ->time('g:i A')
                    ->timezone(config('app.business_timezone')),
                Tables\Columns\TextColumn::make('note')->placeholder('—')->limit(60),
            ])
            ->actions([
                Tables\Actions\Action::make('join')
                    ->label('Join class')
                    ->icon('heroicon-m-video-camera')
                    ->button()
                    ->visible(fn (ClassSession $record): bool => filled($record->meeting_url))
                    ->url(fn (ClassSession $record): ?string => $record->meeting_url, shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading('No upcoming classes')
            ->emptyStateIcon('heroicon-o-calendar')
            ->paginated([5, 10]);
    }
}
