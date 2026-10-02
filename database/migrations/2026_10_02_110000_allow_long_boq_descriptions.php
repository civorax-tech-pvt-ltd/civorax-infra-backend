<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ specifications (e.g. a full tiling or RCC item) are often longer than 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_master_items', fn (Blueprint $table) => $table->text('description')->change());
        Schema::table('boq_items', fn (Blueprint $table) => $table->text('description')->change());
    }

    public function down(): void
    {
        Schema::table('boq_master_items', fn (Blueprint $table) => $table->string('description')->change());
        Schema::table('boq_items', fn (Blueprint $table) => $table->string('description')->change());
    }
};
