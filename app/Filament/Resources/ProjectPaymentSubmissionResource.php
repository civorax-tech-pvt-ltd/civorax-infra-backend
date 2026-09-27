<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectPaymentSubmissionResource\Pages;
use App\Models\ProjectPaymentSubmission;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProjectPaymentSubmissionResource extends Resource
{
    protected static ?string $model = ProjectPaymentSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Client Payment Submissions';

    /**
     * Verifying client money is an admin job; the resource stays out of the team panel.
     */
    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function canCreate(): bool
    {
        return false;
    }

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
                Tables\Columns\TextColumn::make('project.client.contact_person')
                    ->label('Client')
                    ->description(fn (ProjectPaymentSubmission $record): ?string => $record->project?->client?->contact)
                    ->searchable(),
                Tables\Columns\TextColumn::make('project.title')
                    ->label('Project')
                    ->searchable(),
                Tables\Columns\TextColumn::make('milestone.title')
                    ->label('Milestone')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('NPR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_reference')
                    ->label('Transaction ref.')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('screenshot_path')
                    ->label('Screenshot')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectPaymentSubmission::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ProjectPaymentSubmission::STATUSES)
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ProjectPaymentSubmission $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ProjectPaymentSubmission $record): string => 'Records NPR '.number_format((float) $record->amount, 2).' as received for '.$record->project?->title.'. Check the money has actually arrived first.')
                    ->form([
                        Forms\Components\Textarea::make('review_note')->label('Note (optional)'),
                    ])
                    ->action(function (ProjectPaymentSubmission $record, array $data): void {
                        try {
                            $record->approve(auth()->user(), $data['review_note'] ?? null);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Cannot approve')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Payment approved and recorded')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ProjectPaymentSubmission $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Reason (the client sees this)')
                            ->required(),
                    ])
                    ->action(function (ProjectPaymentSubmission $record, array $data): void {
                        $record->reject(auth()->user(), $data['review_note']);

                        Notification::make()->title('Submission rejected')->warning()->send();
                    }),
                Tables\Actions\Action::make('viewScreenshot')
                    ->label('Screenshot')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible(fn (ProjectPaymentSubmission $record): bool => filled($record->screenshot_path))
                    ->url(fn (ProjectPaymentSubmission $record): string => Storage::disk('public')->url($record->screenshot_path))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectPaymentSubmissions::route('/'),
        ];
    }
}
