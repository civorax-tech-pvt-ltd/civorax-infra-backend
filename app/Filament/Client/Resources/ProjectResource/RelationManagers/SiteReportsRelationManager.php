<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\SiteReportResource;
use App\Models\MusterRoll;
use App\Models\SiteReport;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The client's site diary: approved daily reports with photos.
 */
class SiteReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'siteReports';

    protected static ?string $title = 'Site Diary';

    protected static ?string $icon = 'heroicon-o-camera';

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(2)
            ->schema(SiteReportResource::reportEntries());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->approved())
            ->recordTitle(fn (SiteReport $record): string => $record->date->format('l, M j, Y'))
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('D, M j, Y')
                    ->description(fn (SiteReport $record): string => 'B.S. '.MusterRoll::bsDate($record->date))
                    ->sortable(),
                Tables\Columns\TextColumn::make('work_done')
                    ->label('Work done')
                    ->limit(90)
                    ->wrap(),
                Tables\Columns\TextColumn::make('weather')
                    ->formatStateUsing(fn (?string $state): ?string => SiteReport::WEATHER[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('photos_count')
                    ->label('Photos')
                    ->state(fn (SiteReport $record): int => count($record->photos ?? []))
                    ->icon('heroicon-o-photo'),
            ])
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('No site updates yet')
            ->emptyStateDescription('Daily progress reports and photos from the site will appear here.')
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn (SiteReport $record): string => 'Site update · '.$record->date->format('l, M j, Y'))
                    ->modalWidth('4xl'),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
