<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labourer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labour_contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('muster_roll_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('wage');
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('cash');
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'labourer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_payments');
    }
};
