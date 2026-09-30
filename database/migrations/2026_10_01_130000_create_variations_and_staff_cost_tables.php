<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 of project cost tracking: variations (extra work) and staff cost allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2); // added to the contract value
            $table->decimal('cost_budget', 14, 2)->default(0); // expected extra cost
            $table->string('budget_category')->nullable();
            $table->string('client_reference')->nullable();
            $table->date('client_approved_on')->nullable();
            $table->string('document_path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->decimal('monthly_salary', 12, 2)->nullable()->after('designation');
        });

        Schema::create('staff_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->decimal('project_days', 5, 1);
            $table->unsignedSmallInteger('present_days');
            $table->decimal('monthly_salary', 12, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['project_id', 'team_member_id', 'month'], 'staff_alloc_unique');
            $table->index(['team_member_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_cost_allocations');

        Schema::table('team_members', fn (Blueprint $table) => $table->dropColumn('monthly_salary'));

        Schema::dropIfExists('variations');
    }
};
