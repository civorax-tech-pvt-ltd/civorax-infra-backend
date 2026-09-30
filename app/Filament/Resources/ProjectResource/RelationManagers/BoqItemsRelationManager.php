<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\BoqMasterItemResource;
use App\Models\BoqItem;
use App\Models\BoqMasterItem;
use App\Models\KeyMaterial;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The project's BOQ: items picked from the master library (or created on the fly into both), their
 * approved measurements, and progress by quantity (value-weighted for the project).
 */
class BoqItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'boqItems';

    protected static ?string $title = 'BOQ & Progress';

    protected static ?string $icon = 'heroicon-o-calculator';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\TextInput::make('code')->maxLength(50),
                Forms\Components\TextInput::make('description')->required()->columnSpan(2),
                ...static::quantityFields(),
                Forms\Components\Toggle::make('is_variation')
                    ->label('Extra work (variation)')
                    ->inline(false),
                static::normsField()
                    ->visible(fn (): bool => (bool) $this->getOwnerRecord()->track_item_costs)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Material per unit of work (e.g. 6.4 bags of cement per m³), used to estimate an item's material cost
     * when no material was issued to it.
     */
    public static function normsField(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('norms')
            ->label('Material norms (per unit of this work)')
            ->defaultItems(0)
            ->addActionLabel('Add norm')
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('key_material_id')
                    ->label('Material')
                    ->options(fn (): array => KeyMaterial::options())
                    ->required(),
                Forms\Components\TextInput::make('per_unit')
                    ->label('Quantity per unit')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }

    /**
     * @return array<Forms\Components\Component>
     */
    protected static function quantityFields(): array
    {
        return [
            Forms\Components\TextInput::make('unit')
                ->required()
                ->datalist(BoqMasterItem::UNITS),
            Forms\Components\TextInput::make('quantity')
                ->label('BOQ quantity')
                ->numeric()
                ->minValue(0.001)
                ->required(),
            Forms\Components\TextInput::make('rate')
                ->numeric()
                ->minValue(0)
                ->prefix('Rs')
                ->required()
                ->helperText('This project\'s own rate. Library changes never alter it.'),
            Forms\Components\DatePicker::make('planned_start'),
            Forms\Components\DatePicker::make('planned_end')
                ->afterOrEqual('planned_start'),
        ];
    }

    public function getTableDescription(): string|Htmlable|null
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();
        $progress = $project->boqProgress();

        if ($progress === null) {
            return 'No BOQ yet. Add items from the library, create new ones, or copy a previous project\'s BOQ.';
        }

        $delayed = $project->boqItems()->with('latestApprovedMeasurement')->get()->filter(fn (BoqItem $item): bool => $item->scheduleStatus() === 'delayed');

        return "Project progress (by value): {$progress}%"
            .($delayed->isNotEmpty() ? ' · Delayed: '.$delayed->pluck('description')->map(fn (string $d): string => str($d)->limit(40))->implode(', ') : ' · Nothing delayed');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestApprovedMeasurement')->withCount(['measurements as pending_count' => fn (Builder $query) => $query->where('status', 'pending')]))
            ->reorderable('sort')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('description')
                    ->wrap()
                    ->limit(70)
                    ->searchable()
                    ->description(fn (BoqItem $record): ?string => $record->is_variation ? 'Extra work (variation)' : null),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('BOQ qty')
                    ->formatStateUsing(fn (BoqItem $record): string => static::qty($record->quantity).' '.$record->unit),
                Tables\Columns\TextColumn::make('rate')
                    ->money('NPR')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('planned_value')
                    ->label('Amount')
                    ->money('NPR')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')->label('BOQ total')),
                Tables\Columns\TextColumn::make('executed')
                    ->label('Done to date')
                    ->state(fn (BoqItem $record): string => static::qty($record->executedQuantity()).' '.$record->unit)
                    ->description(fn (BoqItem $record): ?string => $record->pending_count ? "{$record->pending_count} awaiting approval" : null),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (BoqItem $record): string => $record->progressPercent().'%')
                    ->badge()
                    ->color(fn (BoqItem $record): string => $record->isExcess() ? 'danger' : ($record->status() === 'completed' ? 'success' : 'gray'))
                    ->description(fn (BoqItem $record): ?string => $record->isExcess() ? 'Excess, variation needed' : null),
                Tables\Columns\TextColumn::make('planned_today')
                    ->label('Planned today')
                    ->state(fn (BoqItem $record): ?string => ($planned = $record->plannedPercentToday()) === null ? null : $planned.'%')
                    ->description(fn (BoqItem $record): ?string => ($variance = $record->scheduleVariance()) === null ? null : ($variance >= 0 ? '+' : '').$variance.' pts')
                    ->placeholder('No dates'),
                Tables\Columns\TextColumn::make('status')
                    ->state(fn (BoqItem $record): string => BoqItem::STATUSES[$record->status()])
                    ->badge()
                    ->color(fn (BoqItem $record): string => match ($record->status()) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('schedule')
                    ->state(fn (BoqItem $record): ?string => match ($record->scheduleStatus()) {
                        'delayed' => 'Delayed',
                        'on_track' => 'On track',
                        default => null,
                    })
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Delayed' ? 'danger' : 'success')
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->addFromLibraryAction(),
                $this->newItemAction(),
                $this->copyFromProjectAction(),
            ])
            ->actions([
                $this->measureAction(),
                Tables\Actions\Action::make('history')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (BoqItem $record): string => "Measurements · {$record->description}")
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (BoqItem $record) => view('filament.boq.measurement-history', ['item' => $record->load('measurements.enteredBy', 'measurements.approver')])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (BoqItem $record): bool => ! $record->measurements()->exists()),
                ]),
            ])
            ->emptyStateHeading('No BOQ items yet')
            ->emptyStateDescription('Use "Add from library", "New item" or "Copy from project" above.');
    }

    protected static function qty(float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3), '0'), '.');
    }

    protected function addFromLibraryAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('addFromLibrary')
            ->label('Add from library')
            ->icon('heroicon-o-magnifying-glass')
            ->modalWidth('3xl')
            ->form([
                Forms\Components\Select::make('master_item_id')
                    ->label('Library item')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => BoqMasterItem::query()
                        ->active()
                        ->where(fn (Builder $query) => $query
                            ->where('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('category', 'like', "%{$search}%"))
                        ->withCount('boqItems')
                        ->orderByDesc('boq_items_count')
                        ->limit(40)
                        ->get()
                        ->mapWithKeys(fn (BoqMasterItem $item): array => [$item->id => $item->label()])
                        ->all())
                    // Before typing: recently used and most-used items first.
                    ->options(fn (): array => BoqMasterItem::query()->active()->withCount('boqItems')
                        ->orderByDesc('boq_items_count')->orderByDesc('updated_at')->limit(25)->get()
                        ->mapWithKeys(fn (BoqMasterItem $item): array => [$item->id => $item->label()])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => BoqMasterItem::find($value)?->label())
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $item = BoqMasterItem::find($state);
                        $set('rate', $item?->default_rate);
                        $set('unit', $item?->unit);
                    })
                    ->helperText('Search by code, description or category. Not listed? Use "New item".')
                    ->columnSpanFull(),
                Forms\Components\Grid::make(3)->schema([
                    ...static::quantityFields(),
                    Forms\Components\Toggle::make('is_variation')
                        ->label('Extra work (variation)')
                        ->inline(false),
                ]),
            ])
            ->action(function (array $data): void {
                $master = BoqMasterItem::findOrFail($data['master_item_id']);

                $this->getOwnerRecord()->boqItems()->create([
                    'master_item_id' => $master->id,
                    'code' => $master->code,
                    'description' => $master->description,
                    'norms' => $master->norms,
                    'unit' => $data['unit'] ?: $master->unit,
                    'quantity' => $data['quantity'],
                    'rate' => $data['rate'],
                    'planned_start' => $data['planned_start'] ?? null,
                    'planned_end' => $data['planned_end'] ?? null,
                    'is_variation' => $data['is_variation'] ?? false,
                    'sort' => (int) $this->getOwnerRecord()->boqItems()->max('sort') + 1,
                    'created_by' => auth()->id(),
                ]);

                Notification::make()->title('Added to the BOQ')->success()->send();
            });
    }

    protected function newItemAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('newItem')
            ->label('New item')
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->modalWidth('3xl')
            ->modalDescription('Saved to this project and to the BOQ library at the same time, so it can be picked for later projects.')
            ->form([
                Forms\Components\Grid::make(2)->schema(
                    collect(BoqMasterItemResource::itemFields('rate'))
                        ->reject(fn (Forms\Components\Component $field): bool => $field->getName() === 'unit')
                        ->all(),
                ),
                Forms\Components\Grid::make(3)->schema([
                    ...collect(static::quantityFields())->reject(fn (Forms\Components\Component $field): bool => $field->getName() === 'rate')->all(),
                    Forms\Components\Toggle::make('is_variation')
                        ->label('Extra work (variation)')
                        ->inline(false),
                ]),
            ])
            ->action(function (array $data): void {
                DB::transaction(function () use ($data): void {
                    $master = BoqMasterItem::create([
                        'code' => $data['code'] ?? null,
                        'description' => $data['description'],
                        'unit' => $data['unit'],
                        'default_rate' => $data['rate'],
                        'category' => $data['category'] ?? null,
                        'rate_includes_vat' => $data['rate_includes_vat'] ?? false,
                        'created_by' => auth()->id(),
                    ]);

                    $this->getOwnerRecord()->boqItems()->create([
                        'master_item_id' => $master->id,
                        'code' => $master->code,
                        'description' => $master->description,
                        'unit' => $master->unit,
                        'quantity' => $data['quantity'],
                        'rate' => $data['rate'],
                        'planned_start' => $data['planned_start'] ?? null,
                        'planned_end' => $data['planned_end'] ?? null,
                        'is_variation' => $data['is_variation'] ?? false,
                        'sort' => (int) $this->getOwnerRecord()->boqItems()->max('sort') + 1,
                        'created_by' => auth()->id(),
                    ]);
                });

                Notification::make()->title('Added to the BOQ and the library')->success()->send();
            });
    }

    protected function copyFromProjectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('copyFromProject')
            ->label('Copy from project')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->form([
                Forms\Components\Select::make('source_project_id')
                    ->label('Copy the BOQ of')
                    ->options(fn (): array => Project::query()
                        ->whereKeyNot($this->getOwnerRecord()->getKey())
                        ->has('boqItems')
                        ->latest()
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Quantities and rates are copied so you can edit them. Measurements are not copied.'),
            ])
            ->action(function (array $data): void {
                $source = Project::findOrFail($data['source_project_id']);
                $next = (int) $this->getOwnerRecord()->boqItems()->max('sort');

                DB::transaction(function () use ($source, &$next): void {
                    foreach ($source->boqItems as $item) {
                        $this->getOwnerRecord()->boqItems()->create([
                            ...$item->only(['master_item_id', 'code', 'description', 'unit', 'quantity', 'rate', 'is_variation', 'norms']),
                            'sort' => ++$next,
                            'created_by' => auth()->id(),
                        ]);
                    }
                });

                Notification::make()->title('Copied '.$source->boqItems->count().' items from '.$source->title)->success()->send();
            });
    }

    protected function measureAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('measure')
            ->label('Measure')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (BoqItem $record): string => "Record progress · {$record->description}")
            ->modalDescription(fn (BoqItem $record): string => 'Enter the total quantity done so far (cumulative), not just today\'s. '
                .'Last approved: '.static::qty($record->executedQuantity())." of {$record->quantity} {$record->unit}.")
            ->form(fn (BoqItem $record): array => [
                Forms\Components\DatePicker::make('measured_date')
                    ->label('Measured on')
                    ->default(now(config('app.business_timezone'))->toDateString())
                    ->maxDate(now(config('app.business_timezone'))->toDateString())
                    ->required(),
                Forms\Components\TextInput::make('executed_quantity')
                    ->label("Total done to date ({$record->unit})")
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): ?string => (float) $get('executed_quantity') > (float) $record->quantity
                        ? 'More than the BOQ quantity: this will show as excess, variation needed.'
                        : null),
                Forms\Components\FileUpload::make('photos')
                    ->label('Site photos')
                    ->image()
                    ->multiple()
                    ->maxFiles(8)
                    ->maxSize(8192)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeTargetHeight('1600')
                    ->disk('public')
                    ->directory('boq-measurements'),
                Forms\Components\Textarea::make('remarks')
                    ->rows(2),
            ])
            ->action(function (BoqItem $record, array $data): void {
                $record->measurements()->create([
                    ...$data,
                    'status' => 'pending',
                    'entered_by' => auth()->id(),
                ]);

                Notification::make()->title('Measurement sent for approval')->success()->send();
            });
    }
}
