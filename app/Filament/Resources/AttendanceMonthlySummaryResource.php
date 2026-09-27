<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceMonthlySummaryResource\Pages;
use App\Filament\Resources\Concerns\ScopesToTeamMember;
use App\Models\AttendanceMonthlySummary;
use App\Models\TeamMember;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AttendanceMonthlySummaryResource extends Resource
{
    use ScopesToTeamMember;

    protected static ?string $model = AttendanceMonthlySummary::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Team';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Monthly Attendance';

    protected static ?string $modelLabel = 'monthly attendance';

    protected static ?string $pluralModelLabel = 'Monthly Attendance';

    public static function canViewAny(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' || auth()->user()?->teamMember !== null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('teamMember'))
            ->columns([
                Tables\Columns\TextColumn::make('month')
                    ->date('F Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('teamMember.fullname')
                    ->label('Team member')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() === 'admin'),
                Tables\Columns\TextColumn::make('present_days')
                    ->label('Days present')
                    ->sortable()
                    ->weight('bold')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')),
                Tables\Columns\TextColumn::make('gps_days')->label('GPS'),
                Tables\Columns\TextColumn::make('manual_days')
                    ->label('Manual')
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null),
                Tables\Columns\TextColumn::make('total_hours')
                    ->label('Hours')
                    ->suffix(' h')
                    ->sortable(),
                Tables\Columns\TextColumn::make('place_days')
                    ->label('Days per site / office')
                    ->state(fn (AttendanceMonthlySummary $record): string => $record->placesSummary())
                    ->wrap(),
            ])
            ->defaultSort('month', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('team_member_id')
                    ->label('Team member')
                    ->relationship('teamMember', 'fullname')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() === 'admin'),
                Tables\Filters\SelectFilter::make('month')
                    ->options(fn (): array => AttendanceMonthlySummary::query()
                        ->distinct()
                        ->orderByDesc('month')
                        ->pluck('month')
                        ->mapWithKeys(fn ($month): array => [$month->toDateString() => $month->format('F Y')])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $month) => $query->whereDate('month', $month))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToTeamMember(
            parent::getEloquentQuery(),
            fn (Builder $query, TeamMember $teamMember) => $query->where('team_member_id', $teamMember->getKey()),
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceMonthlySummaries::route('/'),
        ];
    }
}
