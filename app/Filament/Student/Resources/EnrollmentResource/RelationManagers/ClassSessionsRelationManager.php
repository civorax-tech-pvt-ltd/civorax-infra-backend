<?php

namespace App\Filament\Student\Resources\EnrollmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ClassSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'classSessions';

    protected static ?string $title = 'Class Sessions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('starts_at')
            ->columns([
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->dateTime(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('meeting_url')->label('Meeting Link')->url(fn ($state) => $state)->openUrlInNewTab(),
            ])
            ->defaultSort('starts_at')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
