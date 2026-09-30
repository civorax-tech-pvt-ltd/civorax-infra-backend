<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 of project cost tracking: cost per BOQ item (optional per project).
 */
return new class extends Migration
{
    /**
     * Direct-cost sources that can be tagged to a BOQ item.
     *
     * @var list<string>
     */
    protected array $taggable = ['muster_rolls', 'work_orders', 'purchase_bills', 'equipment_entries', 'petty_cash_claims'];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('track_item_costs')->default(false)->after('manual_progress');
        });

        // Material (or labour) per unit, e.g. [{"key_material_id": 1, "per_unit": 6.4}] bags of cement per m³.
        Schema::table('boq_items', function (Blueprint $table) {
            $table->json('norms')->nullable()->after('is_variation');
        });

        foreach ($this->taggable as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_material_id')->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->decimal('quantity', 14, 2);
            $table->decimal('rate', 14, 2); // weighted-average purchase rate at the time
            $table->decimal('value', 14, 2);
            $table->string('note')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['boq_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issues');

        foreach ($this->taggable as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('boq_item_id'));
        }

        Schema::table('boq_items', fn (Blueprint $table) => $table->dropColumn('norms'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('track_item_costs'));
    }
};
