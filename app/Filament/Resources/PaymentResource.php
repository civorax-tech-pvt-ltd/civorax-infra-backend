<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Models\Project;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->relationship('project', 'title')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('milestone_id', null)),
                ...static::paymentFields(fn (Get $get): ?Project => Project::find($get('project_id'))),
            ]);
    }

    /**
     * Fields shared by this resource and the project page's Payments tab.
     *
     * @param  Closure(Get): ?Project  $project
     * @return array<Forms\Components\Component>
     */
    public static function paymentFields(Closure $project): array
    {
        return [
            Forms\Components\Select::make('milestone_id')
                ->label('For milestone (optional)')
                ->options(fn (Get $get): array => $project($get)?->milestones()->orderBy('sequence')->pluck('title', 'id')->all() ?? [])
                ->disabled(fn (Get $get): bool => $project($get) === null)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state, ?Payment $record) use ($project): void {
                    $left = $project($get)?->milestones()->find($state)?->amountLeft($record);

                    if ($left > 0) {
                        $set('amount', $left);
                    }
                })
                ->helperText(function (Get $get, $state, ?Payment $record) use ($project): string {
                    $milestone = $project($get)?->milestones()->find($state);
                    $billing = $milestone?->billingAmount();

                    if ($milestone === null) {
                        return 'Leave empty for an advance or a lump-sum payment.';
                    }

                    if ($billing === null) {
                        return 'This milestone has no billing % or the project has no fee.';
                    }

                    return "{$milestone->billing_percent}% share: NPR ".number_format($billing, 2)
                        .' · paid NPR '.number_format($milestone->amountPaid($record), 2)
                        .' · left NPR '.number_format($milestone->amountLeft($record), 2)
                        .($milestone->status === 'completed' ? ' · due now' : ' · not yet due (milestone not completed)');
                }),
            Forms\Components\Placeholder::make('balance')
                ->label('Project balance')
                ->content(function (Get $get, ?Payment $record) use ($project): string {
                    $selected = $project($get);

                    if ($selected === null) {
                        return 'Select a project.';
                    }

                    if ($selected->fee === null || (float) $selected->fee <= 0) {
                        return 'No agreed fee yet — payments cannot be recorded.';
                    }

                    $paid = $selected->amountPaid() - ($record?->exists && $record->project_id === $selected->id ? (float) $record->amount : 0);

                    return 'Fee NPR '.number_format((float) $selected->fee, 2)
                        .' · Paid NPR '.number_format($paid, 2)
                        .' · Left NPR '.number_format((float) $selected->fee - $paid, 2);
                }),
            Forms\Components\TextInput::make('amount')
                ->required()
                ->numeric()
                ->prefix('NPR')
                ->rules([
                    fn (Get $get, ?Payment $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record, $project): void {
                        $selected = $project($get);
                        $error = Payment::amountError($selected, (float) $value, $record)
                            ?? Payment::milestoneAmountError($selected?->milestones()->find($get('milestone_id')), (float) $value, $record);

                        if ($error !== null) {
                            $fail($error);
                        }
                    },
                ]),
            Forms\Components\DatePicker::make('received_at')
                ->default(now())
                ->maxDate(now())
                ->required(),
            Forms\Components\TextInput::make('remark')
                ->placeholder('e.g. Cash at office, cheque no. 1234, eSewa ref…')
                ->maxLength(255),
            Forms\Components\Hidden::make('recorded_by')
                ->default(fn () => auth()->id()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.title')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('milestone.title')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('NPR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('received_at')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('remark')
                    ->searchable(),
                Tables\Columns\TextColumn::make('recorder.name')
                    ->label('Recorded By')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
