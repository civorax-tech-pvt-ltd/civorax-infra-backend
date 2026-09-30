<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 of project cost tracking: purchase bills, vendor payments and ledger, petty-cash claims.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    protected array $permissions = ['pay_vendors', 'view_vendor_ledger'];

    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('pan_vat_no')->nullable()->after('contact');
        });

        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('bill_type')->default('pan'); // vat | pan
            $table->string('bill_no');
            $table->string('vendor_pan_vat')->nullable();
            $table->date('bill_date');
            $table->string('category')->default('materials');
            $table->decimal('base_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->boolean('billed_to_company')->default(true);
            $table->boolean('vat_claimable')->default(false);
            $table->json('items')->nullable();
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'bill_no']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('method')->default('cash');
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'paid_on']);
        });

        Schema::create('vendor_balance_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->decimal('balance', 14, 2);
            $table->boolean('agreed')->default(true);
            $table->string('note')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('petty_cash_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('expense_date');
            $table->string('category')->default('site_expenses');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->json('receipts')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->date('reimbursed_on')->nullable();
            $table->foreignId('reimbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::query()->where('name', 'super_admin')->each(fn (Role $role) => $role->givePermissionTo($this->permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_claims');
        Schema::dropIfExists('vendor_balance_confirmations');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('purchase_bills');

        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('pan_vat_no'));

        Permission::query()->whereIn('name', $this->permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
