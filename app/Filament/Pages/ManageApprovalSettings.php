<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Choose which roles approve each kind of entry. Approval checks read these permissions, so who approves
 * can change later without code changes. Super admins can always approve (even their own entries); nobody else approves their own entry.
 */
class ManageApprovalSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Team';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Approval Settings';

    protected static ?string $title = 'Approval Settings';

    protected static string $view = 'filament.pages.manage-payment-settings';

    /**
     * Permission => [label, help].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const POWERS = [
        'approve_boq_measurements' => ['BOQ measurements', 'Approve quantities of work done that engineers enter.'],
        'approve_site_records' => ['Muster rolls & site reports', 'Approve monthly muster rolls and daily site reports (shared with clients).'],
        'pay_labour_wages' => ['Labour wages', 'Record wage payments and advances; see labourer ledgers.'],
        'approve_purchase_bills' => ['Purchase bills', 'Approve supplier bills. Bills not made out to the company always need a super admin.'],
        'approve_petty_cash' => ['Petty-cash claims', 'Approve site expense claims.'],
        'pay_vendors' => ['Pay vendors & reimburse', 'Record payments to vendors and mark petty-cash claims as paid back.'],
        'view_vendor_ledger' => ['Vendor ledger & tax', 'See vendor balances and statements, record balance confirmations, and view Tax & Turnover (e.g. accountant).'],
        'approve_work_orders' => ['Work orders', 'Approve, close or cancel subcontractor work orders (committed cost).'],
        'approve_equipment' => ['Equipment & transport', 'Approve machine hire hours and transport trips.'],
        'approve_variations' => ['Variations', 'Approve extra work that adds to the contract value (the client is told).'],
        'view_project_costs' => ['See cost & profit reports', 'View project budgets, cost reports and profit.'],
        'approve_portfolio_projects' => ['Our Work (portfolio)', 'Publish portfolio projects and approve (or send back) projects submitted by team members.'],
        'approve_blog_posts' => ['Blog posts', 'Publish blog posts directly and approve (or send back) posts submitted by team members.'],
    ];

    /**
     * Team-dashboard charts and the roles that see them (super admins always see them on the admin dashboard).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const DASHBOARD_CHARTS = [
        'view_inquiries_chart' => ['Inquiries by month', 'New inquiries each month and the running total.'],
        'view_clients_chart' => ['Clients by month', 'New clients each month and the running total.'],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allSettings(): array
    {
        return [...self::POWERS, ...self::DASHBOARD_CHARTS];
    }

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (auth()->user()?->hasRole('super_admin') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill(collect(static::allSettings())->mapWithKeys(fn (array $power, string $permission): array => [
            $permission => Role::query()
                ->where('name', '!=', 'super_admin')
                ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
                ->pluck('id')
                ->all(),
        ])->all());
    }

    public function form(Form $form): Form
    {
        $roles = Role::query()->where('name', '!=', 'super_admin')->orderBy('name')->pluck('name', 'id')
            ->map(fn (string $name): string => str($name)->replace('_', ' ')->title()->toString())
            ->all();

        return $form
            ->statePath('data')
            ->schema([
                Section::make('Who approves what')
                    ->description('Super admins can always approve, including their own entries. Anyone else who entered something can never approve it themselves.')
                    ->columns(2)
                    ->schema(collect(self::POWERS)->map(fn (array $power, string $permission): Select => Select::make($permission)
                        ->label($power[0])
                        ->helperText($power[1])
                        ->options($roles)
                        ->multiple()
                        ->placeholder('Super admins only'))
                        ->values()
                        ->all()),
                Section::make('Dashboard charts')
                    ->description('Which roles see these charts on their team dashboard. Super admins always see them on the admin dashboard.')
                    ->columns(2)
                    ->schema(collect(self::DASHBOARD_CHARTS)->map(fn (array $chart, string $permission): Select => Select::make($permission)
                        ->label($chart[0])
                        ->helperText($chart[1])
                        ->options($roles)
                        ->multiple()
                        ->placeholder('Nobody on the team dashboard'))
                        ->values()
                        ->all()),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data): void {
            foreach (array_keys(static::allSettings()) as $permission) {
                $permissionModel = Permission::findOrCreate($permission, 'web');
                $chosen = array_map('intval', $data[$permission] ?? []);

                Role::query()->where('name', '!=', 'super_admin')->each(function (Role $role) use ($permissionModel, $chosen): void {
                    in_array($role->id, $chosen, true)
                        ? $role->givePermissionTo($permissionModel)
                        : $role->revokePermissionTo($permissionModel);
                });
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Notification::make()->title('Approval settings saved')->success()->send();
    }
}
