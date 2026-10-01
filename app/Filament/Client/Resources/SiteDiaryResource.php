<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\SiteDiaryResource\Pages;
use App\Filament\Resources\SiteReportResource;
use App\Models\MusterRoll;
use App\Models\SiteReport;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Approved daily site reports and photos from all of the client's projects.
 */
class SiteDiaryResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = SiteReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Site Diary';

    protected static ?string $modelLabel = 'site update';

    protected static ?string $slug = 'site-diary';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->columns(2)->schema(SiteReportResource::reportEntries());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('D, M j, Y')
                    ->description(fn (SiteReport $record): string => 'B.S. '.MusterRoll::bsDate($record->date))
                    ->sortable(),
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                Tables\Columns\TextColumn::make('work_done')->label('Work done')->limit(90)->wrap(),
                Tables\Columns\TextColumn::make('photos_count')
                    ->label('Photos')
                    ->state(fn (SiteReport $record): int => count($record->photos ?? []))
                    ->icon('heroicon-o-photo'),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->label('Project')->options(fn (): array => static::projectOptions()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn (SiteReport $record): string => 'Site update · '.$record->date->format('l, M j, Y'))
                    ->modalWidth('4xl'),
            ])
            ->emptyStateHeading('No site updates yet')
            ->emptyStateDescription('Daily progress reports and photos from your site appear here.');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::ownProjects(parent::getEloquentQuery()->approved());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSiteDiary::route('/')];
    }
}
