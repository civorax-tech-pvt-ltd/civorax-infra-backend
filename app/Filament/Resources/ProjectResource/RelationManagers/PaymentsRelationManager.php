<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\PaymentResource;
use App\Models\Project;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Form $form): Form
    {
        return $form->schema(PaymentResource::paymentFields(fn (): Project => $this->getOwnerRecord()->refresh()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('remark')
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->date()->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('NPR')->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')->label('Total paid')),
                Tables\Columns\TextColumn::make('milestone.title')->label('Milestone')->placeholder('—'),
                Tables\Columns\TextColumn::make('remark')->placeholder('—'),
                Tables\Columns\TextColumn::make('recorder.name')->label('Recorded by'),
            ])
            ->defaultSort('received_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Record payment'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
