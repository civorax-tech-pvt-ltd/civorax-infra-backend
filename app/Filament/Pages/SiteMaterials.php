<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Models\BoqItem;
use App\Models\KeyMaterial;
use App\Models\MaterialDelivery;
use App\Models\MaterialIssue;
use App\Models\MaterialStockCount;
use App\Models\MaterialTransfer;
use App\Models\Project;
use App\Models\ProjectMaterialPlan;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Key materials on one site: planned vs purchased vs received vs used, with deliveries, monthly counts and transfers.
 */
class SiteMaterials extends Page
{
    use SiteAccess;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Site Materials';

    protected static ?string $title = 'Site Materials';

    protected static ?string $slug = 'site-materials';

    protected static string $view = 'filament.pages.site-materials';

    /**
     * @var array{project_id?: int|string|null}
     */
    public array $filters = [];

    public static function canAccess(): bool
    {
        return static::canUseSite();
    }

    public function mount(): void
    {
        $this->form->fill([
            'project_id' => request()->integer('project') ?: static::siteProjectQuery()->active()->orderBy('title')->value('id'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->live(),
            ]);
    }

    public function project(): ?Project
    {
        return filled($this->filters['project_id'] ?? null) ? static::siteProjectQuery()->find($this->filters['project_id']) : null;
    }

    /**
     * @return array<Forms\Components\Component>
     */
    protected function materialField(): array
    {
        return [
            Forms\Components\Select::make('key_material_id')
                ->label('Material')
                ->options(fn (): array => KeyMaterial::options())
                ->required(),
        ];
    }

    protected function getHeaderActions(): array
    {
        $today = now(config('app.business_timezone'))->toDateString();
        $noProject = fn (): bool => $this->project() === null;

        return [
            Action::make('delivery')
                ->label('Record delivery')
                ->icon('heroicon-o-truck')
                ->disabled($noProject)
                ->form([
                    ...$this->materialField(),
                    Forms\Components\TextInput::make('quantity')->numeric()->minValue(0.01)->required(),
                    Forms\Components\DatePicker::make('delivered_on')->default($today)->maxDate($today)->required(),
                    Forms\Components\Select::make('vendor_id')->label('Supplier')->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                    Forms\Components\TextInput::make('challan_no')->label('Challan no.'),
                    Forms\Components\FileUpload::make('photos')->label('Challan photo')->image()->multiple()->maxFiles(3)->disk('public')->directory('material-challans')
                        ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600'),
                ])
                ->action(function (array $data): void {
                    MaterialDelivery::create([...$data, 'project_id' => $this->project()->id, 'entered_by' => auth()->id()]);
                    Notification::make()->title('Delivery recorded')->success()->send();
                }),
            Action::make('count')
                ->label('Stock count')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->disabled($noProject)
                ->modalDescription('Count what is left on site. Used = received ± transfers − left.')
                ->form([
                    Forms\Components\DatePicker::make('counted_on')->default($today)->maxDate($today)->required(),
                    Forms\Components\Repeater::make('counts')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(2)
                        ->default(fn (): array => KeyMaterial::query()->active()->get()->map(fn (KeyMaterial $m): array => ['key_material_id' => $m->id, 'material' => $m->label(), 'quantity_left' => null])->all())
                        ->itemLabel(fn (array $state): ?string => $state['material'] ?? null)
                        ->schema([
                            Forms\Components\Hidden::make('key_material_id'),
                            Forms\Components\Hidden::make('material'),
                            Forms\Components\TextInput::make('quantity_left')->label('Left on site')->numeric()->minValue(0)->placeholder('Skip if not used here'),
                        ]),
                ])
                ->action(function (array $data): void {
                    $saved = 0;

                    DB::transaction(function () use ($data, &$saved): void {
                        foreach ($data['counts'] as $row) {
                            if ($row['quantity_left'] === null || $row['quantity_left'] === '') {
                                continue;
                            }

                            MaterialStockCount::create(['project_id' => $this->project()->id, 'key_material_id' => $row['key_material_id'], 'counted_on' => $data['counted_on'], 'quantity_left' => $row['quantity_left'], 'entered_by' => auth()->id()]);
                            $saved++;
                        }
                    });

                    Notification::make()->title("Stock count saved for {$saved} ".str('material')->plural($saved))->success()->send();
                }),
            Action::make('transfer')
                ->label('Transfer / return')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->disabled($noProject)
                ->modalDescription('Moves leftover material out of this site at cost. Its value leaves this project (and enters the other one).')
                ->form([
                    ...collect($this->materialField())->map(fn ($field) => $field->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->suggestValue($get, $set)))->all(),
                    Forms\Components\ToggleButtons::make('destination')
                        ->options(['project' => 'To another project', 'supplier' => 'Back to supplier'])
                        ->default('project')
                        ->inline()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('to_project_id')
                        ->label('To project')
                        ->options(fn (): array => static::siteProjectQuery()->whereKeyNot($this->project()?->id)->orderBy('title')->pluck('title', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('destination') === 'project')
                        ->required(fn (Get $get): bool => $get('destination') === 'project'),
                    Forms\Components\Select::make('vendor_id')
                        ->label('Supplier')
                        ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('destination') === 'supplier'),
                    Forms\Components\TextInput::make('quantity')->numeric()->minValue(0.01)->required()->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => $this->suggestValue($get, $set)),
                    Forms\Components\TextInput::make('value')->label('Value at cost')->numeric()->minValue(0)->prefix('Rs')->required()
                        ->helperText('Suggested from this project\'s average purchase rate when bill lines have rates.'),
                    Forms\Components\DatePicker::make('transferred_on')->default($today)->maxDate($today)->required(),
                    Forms\Components\TextInput::make('note'),
                ])
                ->action(function (array $data): void {
                    MaterialTransfer::create([
                        'from_project_id' => $this->project()->id,
                        'to_project_id' => $data['destination'] === 'project' ? $data['to_project_id'] : null,
                        'vendor_id' => $data['destination'] === 'supplier' ? ($data['vendor_id'] ?? null) : null,
                        'key_material_id' => $data['key_material_id'],
                        'quantity' => $data['quantity'],
                        'value' => $data['value'],
                        'transferred_on' => $data['transferred_on'],
                        'note' => $data['note'] ?? null,
                        'entered_by' => auth()->id(),
                    ]);
                    Notification::make()->title('Transfer recorded; project costs updated')->success()->send();
                }),
            Action::make('issue')
                ->label('Issue to BOQ item')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->project()?->track_item_costs)
                ->modalDescription('Records key material used for one BOQ item. It is valued at this site\'s average purchase rate and only splits existing material cost between items.')
                ->form([
                    Forms\Components\Select::make('boq_item_id')
                        ->label('BOQ item')
                        ->options(fn (): array => BoqItem::query()->where('project_id', $this->project()?->id)->orderBy('sort')->get()
                            ->mapWithKeys(fn (BoqItem $item): array => [$item->id => $item->label()])->all())
                        ->searchable()
                        ->required(),
                    ...collect($this->materialField())->map(fn ($field) => $field->live())->all(),
                    Forms\Components\TextInput::make('quantity')->numeric()->minValue(0.01)->required()
                        ->helperText(fn (Get $get): ?string => filled($get('key_material_id')) && $this->project()
                            ? (($rate = $this->project()->materialReport()->averageRate((int) $get('key_material_id'))) === null
                                ? 'No purchase rate yet (name the material on bill lines with a rate).'
                                : 'Valued at Rs '.number_format($rate, 2).' per unit.')
                            : null),
                    Forms\Components\DatePicker::make('issued_on')->default($today)->maxDate($today)->required(),
                    Forms\Components\TextInput::make('note'),
                ])
                ->action(function (array $data): void {
                    MaterialIssue::create([...$data, 'project_id' => $this->project()->id, 'issued_by' => auth()->id()]);
                    Notification::make()->title('Issue recorded; waiting for approval')->success()->send();
                }),
            Action::make('plan')
                ->label('Planned quantities')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->disabled($noProject)
                ->visible(fn (): bool => static::isAdminPanel() || static::canApproveSite())
                ->fillForm(fn (): array => ['plans' => KeyMaterial::query()->active()->get()->map(fn (KeyMaterial $m): array => [
                    'key_material_id' => $m->id,
                    'material' => $m->label(),
                    'planned_quantity' => $this->project()?->materialPlans()->where('key_material_id', $m->id)->value('planned_quantity'),
                ])->all()])
                ->form([
                    Forms\Components\Repeater::make('plans')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => $state['material'] ?? null)
                        ->schema([
                            Forms\Components\Hidden::make('key_material_id'),
                            Forms\Components\Hidden::make('material'),
                            Forms\Components\TextInput::make('planned_quantity')->label('Planned (from estimate)')->numeric()->minValue(0),
                        ]),
                ])
                ->action(function (array $data): void {
                    foreach ($data['plans'] as $row) {
                        filled($row['planned_quantity'])
                            ? ProjectMaterialPlan::query()->updateOrCreate(['project_id' => $this->project()->id, 'key_material_id' => $row['key_material_id']], ['planned_quantity' => $row['planned_quantity']])
                            : ProjectMaterialPlan::query()->where('project_id', $this->project()->id)->where('key_material_id', $row['key_material_id'])->delete();
                    }

                    Notification::make()->title('Planned quantities saved')->success()->send();
                }),
        ];
    }

    public function approveIssue(int $issueId): void
    {
        $issue = MaterialIssue::query()->where('project_id', $this->project()?->id)->findOrFail($issueId);

        if (! MaterialIssue::canBeReviewedBy(auth()->user(), $issue)) {
            Notification::make()->title('You cannot approve this issue')->danger()->send();

            return;
        }

        $issue->approve(auth()->user());
        Notification::make()->title('Material issue approved')->success()->send();
    }

    protected function suggestValue(Get $get, Set $set): void
    {
        $rate = $this->project() && filled($get('key_material_id')) ? $this->project()->materialReport()->averageRate((int) $get('key_material_id')) : null;

        if ($rate !== null && filled($get('quantity'))) {
            $set('value', round($rate * (float) $get('quantity'), 2));
        }
    }
}
