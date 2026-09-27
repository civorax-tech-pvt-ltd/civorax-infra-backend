<?php

namespace App\Filament\Client\Widgets;

use App\Filament\Client\Resources\ProjectResource;
use App\Models\Quotation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class QuotationsAwaitingResponse extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::openQuotations()->exists();
    }

    protected static function openQuotations(): Builder
    {
        return Quotation::query()
            ->where('status', 'sent')
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereHas('project.client', fn (Builder $query) => $query->where('user_id', auth()->id()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Quotations waiting for your response')
            ->description('Review the scope and price, then accept or request changes.')
            ->query(static::openQuotations()->with('project'))
            ->columns([
                Tables\Columns\TextColumn::make('project.title')->label('Project')->weight('bold'),
                Tables\Columns\TextColumn::make('version')->formatStateUsing(fn (int $state): string => "v{$state}"),
                Tables\Columns\TextColumn::make('total')->money('NPR'),
                Tables\Columns\TextColumn::make('valid_until')->label('Valid until')->date()->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-arrow-right')
                    ->button()
                    ->url(fn (Quotation $record): string => ProjectResource::getUrl('view', ['record' => $record->project_id]).'?activeRelationManager=4'),
            ])
            ->paginated(false);
    }
}
