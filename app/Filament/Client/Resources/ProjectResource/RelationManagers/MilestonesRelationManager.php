<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Models\ProjectMilestone;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    protected static ?string $title = 'Milestones';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('sequence')->sortable(),
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\ViewColumn::make('progress')->view('filament.components.progress-bar'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectMilestone::STATUSES[$state] ?? $state),
                Tables\Columns\TextColumn::make('target_date')->date(),
                Tables\Columns\TextColumn::make('completed_at')->date(),
                Tables\Columns\TextColumn::make('billing_percent')->suffix('%'),
            ])
            ->defaultSort('sequence')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    // Bypass Shield's globally-registered ProjectMilestonePolicy (built for the
    // Admin/Team panels) — this panel already scopes visibility via the owner project.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
