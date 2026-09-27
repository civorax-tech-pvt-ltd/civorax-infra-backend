<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\ProjectMilestoneResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    public function form(Form $form): Form
    {
        return $form->schema(ProjectMilestoneResource::milestoneFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns(ProjectMilestoneResource::milestoneColumns())
            ->defaultSort('sequence')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                ProjectMilestoneResource::markCompleteAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
