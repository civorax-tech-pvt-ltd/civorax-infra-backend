<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\VariationResource\Pages;
use App\Models\ProjectCost;
use App\Models\Variation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Extra work the client agreed to pay for; approved variations add to the contract value.
 */
class VariationResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = Variation::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Extra Work (Variations)';

    protected static ?string $modelLabel = 'variation';

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
        return $record->status === 'pending' && ((int) $record->entered_by === (int) auth()->id() || static::isAdminPanel());
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->hasSitePower('approve_variations')) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending')->where('entered_by', '!=', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->label('Extra work')
                    ->placeholder('e.g. Additional parapet wall on terrace')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('amount')
                    ->label('Price to the client')
                    ->helperText('Added to the contract value once approved.')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rs')
                    ->required(),
                Forms\Components\Grid::make(2)->columnSpan(1)->schema([
                    Forms\Components\TextInput::make('cost_budget')
                        ->label('Expected extra cost')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rs'),
                    Forms\Components\Select::make('budget_category')
                        ->label('Cost category')
                        ->options(ProjectCost::CATEGORIES)
                        ->default('materials'),
                ]),
                Forms\Components\TextInput::make('client_reference')
                    ->label('Client approval')
                    ->placeholder('e.g. Signed letter / message on 5 Kartik'),
                Forms\Components\DatePicker::make('client_approved_on')
                    ->label('Client agreed on'),
                Forms\Components\FileUpload::make('document_path')
                    ->label('Client\'s approval (photo / PDF)')
                    ->disk('public')
                    ->directory('variations')
                    ->maxSize(8192)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'enteredBy']))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Extra work')->searchable()->wrap()
                    ->description(fn (Variation $record): ?string => $record->client_reference),
                Tables\Columns\TextColumn::make('project.title')->label('Project')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Price')->money('NPR')->sortable(),
                Tables\Columns\TextColumn::make('cost_budget')->label('Extra cost')->money('NPR')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (Variation $record): ?string => $record->review_note),
                Tables\Columns\TextColumn::make('created_at')->label('Entered')->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Variation::STATUSES),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Variation $record): bool => $record->status === 'pending' && Variation::canBeReviewedBy(auth()->user(), $record))
                    ->modalDescription(fn (Variation $record): string => 'Adds Rs '.number_format((float) $record->amount, 2).' to the contract value of '.$record->project->title.' and tells the client.')
                    ->form([Forms\Components\TextInput::make('note')->label('Note (optional)')])
                    ->action(function (Variation $record, array $data): void {
                        $record->approve(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Approved. Contract value updated.')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Variation $record): bool => $record->status === 'pending' && Variation::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(fn (Variation $record, array $data) => $record->reject(auth()->user(), $data['note'])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageVariations::route('/'),
        ];
    }
}
