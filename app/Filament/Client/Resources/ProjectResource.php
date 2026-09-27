<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\ProjectResource\Pages;
use App\Filament\Client\Resources\ProjectResource\RelationManagers;
use App\Models\Project;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'My Projects';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('client', fn (Builder $query) => $query->where('user_id', auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('projectType.name')
                    ->label('Type')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state)
                    ->searchable(),
                Tables\Columns\ViewColumn::make('progress')
                    ->view('filament.components.progress-bar'),
                Tables\Columns\TextColumn::make('fee')
                    ->money('NPR')
                    ->placeholder('To be quoted')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estimated_end_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MilestonesRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\PaymentSubmissionsRelationManager::class,
            RelationManagers\QuotationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'view' => Pages\ViewProject::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // Bypass Shield's globally-registered ProjectPolicy (built for the Admin/Team
    // panels' resource) — this panel already scopes visibility via getEloquentQuery().
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }
}
