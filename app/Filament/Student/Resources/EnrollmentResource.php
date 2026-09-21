<?php

namespace App\Filament\Student\Resources;

use App\Filament\Student\Resources\EnrollmentResource\Pages;
use App\Filament\Student\Resources\EnrollmentResource\RelationManagers;
use App\Models\Enrollment;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'My Courses';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('student', fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->with(['course', 'coursePayments']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Course')
                    ->searchable(),
                Tables\Columns\TextColumn::make('course.type')
                    ->label('Mode'),
                Tables\Columns\TextColumn::make('course.fee')
                    ->label('Fee')
                    ->money('NPR'),
                Tables\Columns\TextColumn::make('paid')
                    ->label('Paid')
                    ->state(fn ($record) => $record->coursePayments->sum('amount'))
                    ->money('NPR'),
                Tables\Columns\TextColumn::make('enrolled_at')
                    ->date()
                    ->sortable(),
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
            RelationManagers\ClassSessionsRelationManager::class,
            RelationManagers\CoursePaymentsRelationManager::class,
            RelationManagers\PaymentSubmissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'view' => Pages\ViewEnrollment::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // Bypass Shield's globally-registered EnrollmentPolicy (built for the Admin/Team
    // panels' resource) — this panel already scopes visibility via getEloquentQuery().
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return true;
    }
}
