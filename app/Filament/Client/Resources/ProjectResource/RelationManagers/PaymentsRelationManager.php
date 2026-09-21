<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payment History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('remark')
            ->columns([
                Tables\Columns\TextColumn::make('milestone.title')->label('Milestone'),
                Tables\Columns\TextColumn::make('amount')->money('NPR'),
                Tables\Columns\TextColumn::make('received_at')->date(),
                Tables\Columns\TextColumn::make('remark'),
            ])
            ->defaultSort('received_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
