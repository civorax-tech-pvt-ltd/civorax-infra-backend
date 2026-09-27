<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_template_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedTinyInteger('weight')->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_template_tasks');
    }
};
