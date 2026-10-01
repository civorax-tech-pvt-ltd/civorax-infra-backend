<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Models\ProjectDocument;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectDocument::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'agreement' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('version'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->label('Uploaded'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => $record->url())
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    // Bypass Shield's globally-registered ProjectDocumentPolicy (built for the
    // Admin/Team panels) — this panel already scopes visibility via the owner project.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
