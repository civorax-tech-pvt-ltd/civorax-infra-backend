<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of project cost and progress tracking (see project_cost_tracking_plan.md).
 * Money is decimal(14,2); quantities decimal(14,3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('vat_registered')->default(false);
            $table->date('vat_registration_date')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(13);
            $table->decimal('vat_registration_limit', 14, 2)->nullable();
            $table->unsignedTinyInteger('vat_warning_percent')->default(80);
            // 1 = Baisakh … 4 = Shrawan (Nepal's fiscal year starts in Shrawan).
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(4);
            $table->timestamps();
        });

        Schema::create('boq_master_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->string('description');
            $table->string('unit');
            $table->decimal('default_rate', 14, 2)->default(0);
            $table->string('category')->nullable();
            $table->boolean('rate_includes_vat')->default(false);
            $table->json('norms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('rate_updated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });

        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('master_item_id')->nullable()->constrained('boq_master_items')->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('description');
            $table->string('unit');
            $table->decimal('quantity', 14, 3);
            $table->decimal('rate', 14, 2);
            $table->decimal('planned_value', 14, 2);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->boolean('is_variation')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'sort']);
        });

        Schema::create('boq_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->date('measured_date');
            $table->decimal('executed_quantity', 14, 3); // cumulative to date
            $table->json('photos')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['boq_item_id', 'status', 'measured_date']);
        });

        Schema::create('project_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'category']);
        });

        Schema::create('project_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            // Supplier VAT inside `amount` that could not be claimed back (report line "VAT paid to suppliers").
            $table->decimal('vat_not_claimable', 14, 2)->default(0);
            $table->date('entry_date');
            $table->string('status')->default('approved');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['project_id', 'status', 'category']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('client_type')->nullable()->after('client_id');
            $table->string('price_basis')->default('vat_inclusive')->after('fee');
            $table->unsignedTinyInteger('manual_progress')->nullable()->after('progress');
            $table->decimal('cost_to_finish_override', 14, 2)->nullable()->after('price_basis');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'price_basis', 'manual_progress', 'cost_to_finish_override']);
        });

        Schema::dropIfExists('project_costs');
        Schema::dropIfExists('project_budgets');
        Schema::dropIfExists('boq_measurements');
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('boq_master_items');
        Schema::dropIfExists('company_settings');
    }
};
