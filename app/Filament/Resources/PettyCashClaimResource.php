<?php

namespace App\Filament\Resources;

use App\Filament\Forms\BoqItemTagField;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\PettyCashClaimResource\Pages;
use App\Models\PettyCashClaim;
use App\Models\ProjectCost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Small site expenses paid from a supervisor's pocket and claimed back with a receipt photo.
 */
class PettyCashClaimResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = PettyCashClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Petty Cash';

    protected static ?string $modelLabel = 'petty-cash claim';

    public static function canViewAny(): bool
    {
        return static::canUseSite();
    }

    public static function canCreate(): bool
    {
        return static::canUseSite();
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'pending' && ((int) $record->claimed_by === (int) auth()->id() || static::isAdminPanel());
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->hasSitePower('approve_petty_cash')) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending')->where('claimed_by', '!=', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),
                Forms\Components\DatePicker::make('expense_date')
                    ->label('Date spent')
                    ->default(now(config('app.business_timezone'))->toDateString())
                    ->maxDate(now(config('app.business_timezone'))->toDateString())
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->label('What for')
                    ->placeholder('e.g. Tea for labour, nails, tipper fuel')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rs')
                    ->required(),
                Forms\Components\Select::make('category')
                    ->label('Cost category')
                    ->options(collect(ProjectCost::CATEGORIES)->only(['site_expenses', 'transport', 'materials', 'equipment'])->all())
                    ->default('site_expenses')
                    ->required()
                    ->selectablePlaceholder(false),
                BoqItemTagField::make(),
                Forms\Components\FileUpload::make('receipts')
                    ->label('Receipt photo')
                    ->image()
                    ->multiple()
                    ->maxFiles(4)
                    ->maxSize(8192)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeTargetHeight('1600')
                    ->disk('public')
                    ->directory('petty-cash')
                    ->helperText('No receipt? Explain in "What for"; the approver decides.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'claimant']))
            ->columns([
                Tables\Columns\TextColumn::make('expense_date')->label('Date')->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('description')->label('What for')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('project.title')->label('Site')->toggleable(),
                Tables\Columns\TextColumn::make('claimant.name')->label('Claimed by'),
                Tables\Columns\TextColumn::make('amount')->money('NPR')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, PettyCashClaim $record): string => (PettyCashClaim::STATUSES[$state] ?? $state).($record->reimbursed_on ? ' · reimbursed' : ''))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (PettyCashClaim $record): ?string => $record->review_note),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PettyCashClaim::STATUSES),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all()),
                Tables\Filters\TernaryFilter::make('reimbursed_on')->label('Reimbursed')->nullable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PettyCashClaim $record): bool => $record->status === 'pending' && PettyCashClaim::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Note (optional)')])
                    ->action(function (PettyCashClaim $record, array $data): void {
                        $record->approve(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Claim approved and added to project cost')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PettyCashClaim $record): bool => $record->status === 'pending' && PettyCashClaim::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(function (PettyCashClaim $record, array $data): void {
                        $record->reject(auth()->user(), $data['note']);
                        Notification::make()->title('Claim rejected')->warning()->send();
                    }),
                Tables\Actions\Action::make('reimburse')
                    ->label('Mark paid back')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->visible(fn (PettyCashClaim $record): bool => $record->status === 'approved' && $record->reimbursed_on === null && auth()->user()?->hasSitePower('pay_vendors'))
                    ->form([Forms\Components\DatePicker::make('on')->label('Paid back on')->default(now(config('app.business_timezone'))->toDateString())->required()])
                    ->action(function (PettyCashClaim $record, array $data): void {
                        $record->markReimbursed(auth()->user(), $data['on']);
                        Notification::make()->title('Marked as paid back')->success()->send();
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->modalContent(fn (PettyCashClaim $record) => view('filament.costs.bill-photos', ['urls' => $record->receiptUrls()])),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePettyCashClaims::route('/'),
        ];
    }
}
