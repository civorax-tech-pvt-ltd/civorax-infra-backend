<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CoursePaymentSubmissionResource\Pages;
use App\Models\CoursePaymentSubmission;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

class CoursePaymentSubmissionResource extends Resource
{
    protected static ?string $model = CoursePaymentSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Academy';

    protected static ?string $navigationLabel = 'Payment Submissions';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('enrollment.student.fullname')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('enrollment.course.title')
                    ->label('Course')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('NPR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_reference')
                    ->label('Transaction Ref.')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('screenshot_path')
                    ->label('Screenshot')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CoursePaymentSubmission $record) => $record->status === 'pending')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Note (optional)'),
                    ])
                    ->action(function (CoursePaymentSubmission $record, array $data) {
                        $record->approve(auth()->user(), $data['review_note'] ?? null);

                        Notification::make()
                            ->title('Payment approved and recorded')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CoursePaymentSubmission $record) => $record->status === 'pending')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Reason for rejection')
                            ->required(),
                    ])
                    ->action(function (CoursePaymentSubmission $record, array $data) {
                        $record->reject(auth()->user(), $data['review_note']);

                        Notification::make()
                            ->title('Payment submission rejected')
                            ->warning()
                            ->send();
                    }),
                Tables\Actions\Action::make('viewScreenshot')
                    ->label('View Screenshot')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible(fn (CoursePaymentSubmission $record) => filled($record->screenshot_path))
                    ->url(fn (CoursePaymentSubmission $record) => Storage::disk('public')->url($record->screenshot_path))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoursePaymentSubmissions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
