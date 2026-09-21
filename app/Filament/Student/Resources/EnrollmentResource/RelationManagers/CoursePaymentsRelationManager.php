<?php

namespace App\Filament\Student\Resources\EnrollmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CoursePaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'coursePayments';

    protected static ?string $title = 'Payment History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('remark')
            ->columns([
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

    // Bypass Shield's globally-registered CoursePaymentPolicy (built for the Admin/Team
    // panels) — this panel already scopes visibility via the owner enrollment.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
