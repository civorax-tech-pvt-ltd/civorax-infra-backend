<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who approves each kind of cost entry, and who sees cost reports. Super admins always can;
 * other roles are chosen under Settings › Approval Settings.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    protected array $permissions = [
        'approve_boq_measurements', 'approve_purchase_bills', 'approve_petty_cash',
        'approve_work_orders', 'approve_variations', 'view_project_costs',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::query()->where('name', 'super_admin')->each(fn (Role $role) => $role->givePermissionTo($this->permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->permissions)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
