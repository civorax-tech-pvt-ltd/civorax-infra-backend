<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\DocumentResource\Pages;
use App\Models\ProjectDocument;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Drawings, designs and other files shared with the client.
 */
class DocumentResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = ProjectDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'Files';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $slug = 'documents';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectDocument::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'agreement' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('version')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Shared')->date()->sortable(),
            ])
            // Agreements first, then newest.
            ->defaultSort(fn (Builder $query) => $query->orderByRaw("CASE WHEN type = 'agreement' THEN 0 ELSE 1 END")->orderByDesc('created_at'))
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->label('Project')->options(fn (): array => static::projectOptions()),
                Tables\Filters\SelectFilter::make('type')->options(ProjectDocument::TYPES),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (ProjectDocument $record): string => $record->url(), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading('No documents shared yet');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::ownProjects(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocuments::route('/')];
    }
}
