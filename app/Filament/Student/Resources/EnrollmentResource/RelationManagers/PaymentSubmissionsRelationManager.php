<?php

namespace App\Filament\Student\Resources\EnrollmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentSubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'coursePaymentSubmissions';

    protected static ?string $title = 'My Payment Submissions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_reference')
            ->columns([
                Tables\Columns\TextColumn::make('amount')->money('NPR'),
                Tables\Columns\TextColumn::make('transaction_reference')->label('Reference'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('review_note')->label('Note')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime(),
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

    // Bypass Shield's globally-registered CoursePaymentSubmissionPolicy (built for the
    // Admin/Team panels) — this panel already scopes visibility via the owner enrollment.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
