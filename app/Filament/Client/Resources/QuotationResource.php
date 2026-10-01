<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * All quotations sent to the client; accepting or requesting changes happens on the project page.
 */
class QuotationResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Quotations';

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getEloquentQuery()->where('status', 'sent')->count();

        return $waiting ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for your response';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->columns([
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                Tables\Columns\TextColumn::make('version')->formatStateUsing(fn (int $state): string => "v{$state}"),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quotation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'changes_requested' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('total')->money('NPR'),
                Tables\Columns\TextColumn::make('valid_until')->date()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(fn (Quotation $record): string => $record->status === 'sent' ? 'Review & respond' : 'Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Quotation $record): string => static::projectUrl($record->project_id, 4)),
            ])
            ->emptyStateHeading('No quotations yet');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::ownProjects(parent::getEloquentQuery()->whereIn('status', ['sent', 'changes_requested', 'accepted']));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQuotations::route('/')];
    }
}
