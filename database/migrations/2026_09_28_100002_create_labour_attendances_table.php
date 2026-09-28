<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labourer_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->decimal('overtime_hours', 4, 1)->default(0);
            $table->decimal('wage_rate', 10, 2);
            $table->string('work_type');
            $table->string('note')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A labourer is paid for one site per day.
            $table->unique(['labourer_id', 'date']);
            $table->index(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_attendances');
    }
};
