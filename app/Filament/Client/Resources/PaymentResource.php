<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\Concerns\ClientReadOnly;
use App\Filament\Client\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every payment received from the client, across projects.
 */
class PaymentResource extends Resource
{
    use ClientReadOnly;

    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Payment History';

    protected static ?string $slug = 'payments';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'milestone']))
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->label('Date')->date()->sortable(),
                Tables\Columns\TextColumn::make('project.title')->label('Project'),
                Tables\Columns\TextColumn::make('milestone.title')->label('For milestone')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->money('NPR')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')->label('Total paid')),
                Tables\Columns\TextColumn::make('remark')->placeholder('—')->wrap(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->label('Project')->options(fn (): array => static::projectOptions()),
            ])
            ->emptyStateHeading('No payments yet');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::ownProjects(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
