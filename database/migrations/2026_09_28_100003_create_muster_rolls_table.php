<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muster_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('calendar')->default('bs');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'starts_on']);
        });

        Schema::create('muster_roll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muster_roll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labourer_id')->constrained()->cascadeOnDelete();
            $table->string('work_type');
            $table->json('days');
            $table->decimal('present_days', 5, 1)->default(0);
            $table->decimal('overtime_hours', 6, 1)->default(0);
            $table->decimal('wage_rate', 10, 2);
            $table->decimal('total_wage', 12, 2);
            $table->string('arrear_reason')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['muster_roll_id', 'labourer_id']);
        });

        Schema::create('muster_roll_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muster_roll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labourer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('mb_ref')->nullable();
            $table->string('remarks')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muster_roll_works');
        Schema::dropIfExists('muster_roll_lines');
        Schema::dropIfExists('muster_rolls');
    }
};
