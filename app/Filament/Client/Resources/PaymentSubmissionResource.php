<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\PaymentSubmissionResource\Pages;
use App\Models\ProjectPaymentSubmission;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments the client submitted for verification and what happened to them.
 */
class PaymentSubmissionResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = ProjectPaymentSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Submitted Payments';

    protected static ?string $slug = 'submitted-payments';

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->where('status', 'pending')->count();

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for our verification';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime('M j, Y g:i A')->sortable(),
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                Tables\Columns\TextColumn::make('amount')->money('NPR'),
                Tables\Columns\TextColumn::make('transaction_reference')->label('Reference'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Being verified',
                        'approved' => 'Verified',
                        'rejected' => 'Not verified',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('review_note')->label('Note from us')->placeholder('—')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No submitted payments');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::ownProjects(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPaymentSubmissions::route('/')];
    }
}
