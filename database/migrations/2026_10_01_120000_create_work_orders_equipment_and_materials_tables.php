<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3 of project cost tracking: subcontractor work orders, equipment / transport entries, key materials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('scope');
            $table->text('terms')->nullable();
            $table->decimal('agreed_amount', 14, 2);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, closed, cancelled
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
        });

        Schema::create('equipment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('equipment'); // equipment | transport
            $table->string('description');
            $table->date('entry_date');
            $table->string('unit')->default('hour');
            $table->decimal('quantity', 12, 2);
            $table->decimal('rate', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->json('photos')->nullable();
            $table->string('note')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('key_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('project_material_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_material_id')->constrained()->cascadeOnDelete();
            $table->decimal('planned_quantity', 14, 2);
            $table->timestamps();

            $table->unique(['project_id', 'key_material_id']);
        });

        Schema::create('material_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_material_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('delivered_on');
            $table->decimal('quantity', 14, 2);
            $table->string('challan_no')->nullable();
            $table->json('photos')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'key_material_id'], 'deliveries_lookup_index');
        });

        Schema::create('material_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_material_id')->constrained()->restrictOnDelete();
            $table->date('counted_on');
            $table->decimal('quantity_left', 14, 2);
            $table->string('note')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'key_material_id', 'counted_on'], 'stock_counts_lookup_index'); // short name: MySQL allows 64 chars
        });

        Schema::create('material_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('to_project_id')->nullable()->constrained('projects')->nullOnDelete(); // null = returned to supplier
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('key_material_id')->constrained()->restrictOnDelete();
            $table->date('transferred_on');
            $table->decimal('quantity', 14, 2);
            $table->decimal('value', 14, 2);
            $table->string('note')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('key_materials')->insert(collect([
            ['Cement', 'bags'], ['Reinforcement rod', 'kg'], ['Bricks', 'pcs'], ['Sand', 'cft'], ['Aggregate', 'cft'], ['Tiles', 'sq.ft'],
        ])->map(fn (array $row, int $i): array => ['name' => $row[0], 'unit' => $row[1], 'is_active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now])->all());

        Permission::findOrCreate('approve_equipment', 'web');
        Role::query()->where('name', 'super_admin')->each(fn (Role $role) => $role->givePermissionTo('approve_equipment'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('material_transfers');
        Schema::dropIfExists('material_stock_counts');
        Schema::dropIfExists('material_deliveries');
        Schema::dropIfExists('project_material_plans');
        Schema::dropIfExists('key_materials');
        Schema::dropIfExists('equipment_entries');

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
        });

        Schema::dropIfExists('work_orders');

        Permission::query()->where('name', 'approve_equipment')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
