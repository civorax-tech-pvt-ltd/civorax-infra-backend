<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\WorkProgressResource\Pages;
use App\Models\BoqItem;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * BOQ progress the admin chose to share: quantity done and % complete only, never rates or costs.
 */
class WorkProgressResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = BoqItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Work Progress';

    protected static ?string $modelLabel = 'work item';

    protected static ?string $slug = 'work-progress';

    /**
     * Only in the menu when at least one of the client's projects shares its BOQ.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->exists();
    }

    public static function table(Table $table): Table
    {
        return static::progressTable($table)
            ->columns([
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                ...static::progressColumns(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->label('Project')->options(fn (): array => static::projectOptions()),
            ]);
    }

    /**
     * Shared with the project page's "Work Progress" tab.
     */
    public static function progressTable(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestApprovedMeasurement'))
            ->defaultSort('sort')
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('No work items shared yet');
    }

    /**
     * @return array<Tables\Columns\Column>
     */
    public static function progressColumns(): array
    {
        $qty = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

        return [
            Tables\Columns\TextColumn::make('description')->label('Work')->wrap()
                ->description(fn (BoqItem $record): ?string => $record->is_variation ? 'Extra work' : null),
            Tables\Columns\TextColumn::make('quantity')->label('Total')
                ->formatStateUsing(fn (BoqItem $record): string => $qty($record->quantity).' '.$record->unit),
            Tables\Columns\TextColumn::make('done')->label('Done so far')
                ->state(fn (BoqItem $record): string => $qty(min((float) $record->quantity, $record->executedQuantity())).' '.$record->unit),
            Tables\Columns\ViewColumn::make('percent')->label('Progress')
                ->state(fn (BoqItem $record): int => (int) round(min(100, $record->progressPercent())))
                ->view('filament.components.progress-bar'),
            Tables\Columns\TextColumn::make('status')
                ->state(fn (BoqItem $record): string => BoqItem::STATUSES[$record->status()])
                ->badge()
                ->color(fn (BoqItem $record): string => match ($record->status()) {
                    'completed' => 'success',
                    'in_progress' => 'warning',
                    default => 'gray',
                }),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('project_id', Project::query()
            ->where('share_boq_with_client', true)
            ->whereHas('client', fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->select('projects.id'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWorkProgress::route('/')];
    }
}
