<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Models\Quotation;
use App\Models\QuotationItem;
use Filament\Forms\Components\Textarea;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations';

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(3)
            ->schema([
                Infolists\Components\RepeatableEntry::make('items')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('description')->columnSpan(2),
                        Infolists\Components\TextEntry::make('quantity')
                            ->formatStateUsing(fn (QuotationItem $record): string => rtrim(rtrim((string) $record->quantity, '0'), '.').' '.$record->unit),
                        Infolists\Components\TextEntry::make('amount')->money('NPR'),
                    ]),
                Infolists\Components\TextEntry::make('discount')->money('NPR'),
                Infolists\Components\TextEntry::make('vat_percent')->label('VAT')->suffix('%'),
                Infolists\Components\TextEntry::make('total')->money('NPR')->weight('bold'),
                Infolists\Components\TextEntry::make('notes')->label('Terms & notes')->columnSpanFull()->placeholder('—'),
                Infolists\Components\TextEntry::make('client_note')
                    ->label('Your requested changes')
                    ->columnSpanFull()
                    ->visible(fn (Quotation $record): bool => filled($record->client_note)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Quotation $record): string => $record->label())
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['sent', 'changes_requested', 'accepted']))
            ->columns([
                Tables\Columns\TextColumn::make('version')->formatStateUsing(fn (int $state): string => "v{$state}"),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quotation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'changes_requested' => 'warning',
                        default => 'info',
                    })
                    ->description(fn (Quotation $record): ?string => match (true) {
                        $record->status === 'changes_requested' => 'We are preparing a revised quotation.',
                        $record->status === 'sent' && ! $record->isOpenForClient() => 'Expired — ask us for an updated quotation.',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('total')->money('NPR'),
                Tables\Columns\TextColumn::make('valid_until')->date()->placeholder('—'),
                Tables\Columns\TextColumn::make('accepted_at')->label('Accepted on')->dateTime()->placeholder('—'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Quotation $record): bool => $record->isOpenForClient())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Quotation $record): string => "Accept {$record->label()}?")
                    ->modalDescription(fn (Quotation $record): string => 'You agree to the scope and price of NPR '.number_format((float) $record->total, 2)
                        .'. This becomes the contract fee for this project.')
                    ->modalSubmitActionLabel('Yes, I accept')
                    ->action(function (Quotation $record): void {
                        if (! $record->refresh()->isOpenForClient()) {
                            Notification::make()->title('This quotation can no longer be accepted')->danger()->send();

                            return;
                        }

                        $record->accept(auth()->user());

                        Notification::make()
                            ->title('Quotation accepted')
                            ->body('Thank you. Our team has been informed and the project fee is now set.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('requestChanges')
                    ->label('Request changes')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('gray')
                    ->visible(fn (Quotation $record): bool => $record->isOpenForClient())
                    ->modalHeading(fn (Quotation $record): string => "Request changes to {$record->label()}")
                    ->form([
                        Textarea::make('note')
                            ->label('What would you like changed?')
                            ->placeholder('e.g. Please remove the 3D renders and reduce the site visits to one.')
                            ->required()
                            ->maxLength(2000)
                            ->rows(4),
                    ])
                    ->modalSubmitActionLabel('Send request')
                    ->action(function (Quotation $record, array $data): void {
                        if (! $record->refresh()->isOpenForClient()) {
                            Notification::make()->title('This quotation can no longer be changed')->danger()->send();

                            return;
                        }

                        $record->requestChanges(auth()->user(), $data['note']);

                        Notification::make()
                            ->title('Request sent')
                            ->body('We will send you a revised quotation.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    // This panel already scopes visibility via the owner project, like the other client tabs.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
