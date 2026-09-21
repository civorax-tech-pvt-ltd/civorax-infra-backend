<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_type_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('site_address');
            $table->string('city')->nullable();
            $table->string('ward_no')->nullable();
            $table->string('status')->default('inquiry');
            $table->decimal('fee', 14, 2);
            $table->date('estimated_end_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
