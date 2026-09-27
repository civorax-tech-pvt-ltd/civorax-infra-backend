<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Models\Quotation;
use App\Models\QuotationItem;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations';

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(3)
            ->schema([
                Infolists\Components\RepeatableEntry::make('items')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('description')->columnSpan(2),
                        Infolists\Components\TextEntry::make('quantity')
                            ->formatStateUsing(fn (QuotationItem $record): string => rtrim(rtrim((string) $record->quantity, '0'), '.').' '.$record->unit),
                        Infolists\Components\TextEntry::make('amount')->money('NPR'),
                    ]),
                Infolists\Components\TextEntry::make('discount')->money('NPR'),
                Infolists\Components\TextEntry::make('vat_percent')->label('VAT')->suffix('%'),
                Infolists\Components\TextEntry::make('total')->money('NPR')->weight('bold'),
                Infolists\Components\TextEntry::make('notes')->label('Terms & notes')->columnSpanFull()->placeholder('—'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Quotation $record): string => $record->label())
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['sent', 'accepted']))
            ->columns([
                Tables\Columns\TextColumn::make('version')->formatStateUsing(fn (int $state): string => "v{$state}"),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quotation::STATUSES[$state] ?? $state),
                Tables\Columns\TextColumn::make('total')->money('NPR'),
                Tables\Columns\TextColumn::make('valid_until')->date(),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    // This panel already scopes visibility via the owner project, like the other client tabs.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
