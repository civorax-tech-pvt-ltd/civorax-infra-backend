<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Models\Quotation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    public function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Select::make('status')
                    ->options(array_diff_key(Quotation::STATUSES, array_flip(['accepted', 'superseded'])))
                    ->default('draft')
                    ->required()
                    ->disabled(fn (?Quotation $record): bool => in_array($record?->status, ['accepted', 'superseded'], true))
                    ->helperText('"Sent" shows it in the client portal, where the client can accept it or request changes.'),
                Forms\Components\Placeholder::make('client_request')
                    ->label('Client requested changes')
                    ->content(fn (?Quotation $record): string => ($record?->client_note ?? '').' ('.$record?->client_responded_at?->format('M j, Y g:i A').')')
                    ->visible(fn (?Quotation $record): bool => filled($record?->client_note))
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('valid_until')
                    ->default(now()->addDays(30)),
                Forms\Components\Hidden::make('created_by')
                    ->default(fn () => auth()->id()),
                Forms\Components\Repeater::make('items')
                    ->relationship()
                    ->orderColumn('sort')
                    ->required()
                    ->minItems(1)
                    ->columnSpanFull()
                    ->columns(12)
                    ->addActionLabel('Add line')
                    ->helperText('For "% of cost", enter the construction cost as quantity and the percentage as rate.')
                    ->schema([
                        Forms\Components\TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(5),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->minValue(0)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(2),
                        Forms\Components\Select::make('unit')
                            ->options(Quotation::UNITS)
                            ->default('lump sum')
                            ->required()
                            ->live()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('rate')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(2),
                        Forms\Components\Placeholder::make('line_amount')
                            ->label('Amount')
                            ->content(fn (Get $get): string => number_format(static::lineAmount($get), 2))
                            ->columnSpan(1),
                    ]),
                Forms\Components\TextInput::make('discount')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('NPR')
                    ->live(onBlur: true),
                Forms\Components\TextInput::make('vat_percent')
                    ->label('VAT')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->suffix('%')
                    ->live(onBlur: true),
                Forms\Components\Placeholder::make('estimated_total')
                    ->label('Total')
                    ->content(function (Get $get): string {
                        $subtotal = collect($get('items') ?? [])->sum(fn (array $item): float => static::lineAmount(fn (string $key) => $item[$key] ?? null));
                        $total = max(0, $subtotal - (float) $get('discount')) * (1 + (float) $get('vat_percent') / 100);

                        return 'NPR '.number_format($total, 2);
                    }),
                Forms\Components\Textarea::make('notes')
                    ->label('Terms & notes')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Quotation $record): string => $record->label())
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->formatStateUsing(fn (int $state): string => "v{$state}"),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quotation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'sent' => 'info',
                        'changes_requested' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Quotation $record): ?string => $record->status === 'changes_requested' && filled($record->client_note)
                        ? 'Client: '.str($record->client_note)->limit(120)
                        : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('subtotal')->money('NPR'),
                Tables\Columns\TextColumn::make('discount')->money('NPR')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('vat_percent')->label('VAT')->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('total')->money('NPR')->weight('bold'),
                Tables\Columns\TextColumn::make('valid_until')->date(),
                Tables\Columns\TextColumn::make('accepted_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn (Quotation $record): ?string => $record->acceptedBy
                        ? 'by '.$record->acceptedBy->name.($record->acceptedBy->client ? ' (client, online)' : '')
                        : null),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('New quotation')
                    ->after(fn (Quotation $record) => $record->recalculateTotals()),
            ])
            ->actions([
                Tables\Actions\Action::make('accept')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Quotation $record): bool => in_array($record->status, ['draft', 'sent', 'changes_requested'], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Quotation $record): string => "Accept {$record->label()}?")
                    ->modalDescription(fn (Quotation $record): string => 'The project fee becomes NPR '.number_format((float) $record->total, 2).'. Any earlier accepted quotation is marked superseded.')
                    ->action(function (Quotation $record): void {
                        $record->accept();

                        Notification::make()->title('Quotation accepted — project fee updated')->success()->send();
                    }),
                Tables\Actions\Action::make('undoAcceptance')
                    ->label('Undo acceptance')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Quotation $record): bool => $record->status === 'accepted')
                    ->requiresConfirmation()
                    ->modalDescription('Use this only if the quotation was accepted by mistake. It goes back to Sent so you can edit or delete it, and the project fee returns to the previously accepted quotation (or becomes empty).')
                    ->action(function (Quotation $record): void {
                        $error = $record->undoAcceptanceError();

                        if ($error !== null) {
                            Notification::make()->title('Cannot undo acceptance')->body($error)->danger()->send();

                            return;
                        }

                        $record->undoAcceptance();

                        Notification::make()->title('Acceptance undone — project fee updated')->success()->send();
                    }),
                Tables\Actions\ViewAction::make()
                    ->visible(fn (Quotation $record): bool => in_array($record->status, ['accepted', 'superseded'], true)),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Quotation $record): bool => ! in_array($record->status, ['accepted', 'superseded'], true))
                    ->after(fn (Quotation $record) => $record->recalculateTotals()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Quotation $record): bool => $record->status !== 'accepted'),
            ]);
    }

    /**
     * @param  Get|\Closure(string): mixed  $get
     */
    protected static function lineAmount(Get|\Closure $get): float
    {
        $amount = (float) $get('quantity') * (float) $get('rate');

        return $get('unit') === '%' ? $amount / 100 : $amount;
    }
}
