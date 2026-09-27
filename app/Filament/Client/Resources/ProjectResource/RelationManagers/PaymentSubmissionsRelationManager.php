<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Models\ProjectPaymentSubmission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentSubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentSubmissions';

    protected static ?string $title = 'Payment submissions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_reference')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime(),
                Tables\Columns\TextColumn::make('amount')->money('NPR'),
                Tables\Columns\TextColumn::make('transaction_reference')->label('Reference'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectPaymentSubmission::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('review_note')->label('Note from us')->placeholder('—')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
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
