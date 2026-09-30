<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Logos that already spell out the company name don't need it printed again underneath.
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->boolean('show_name_with_logo')->default(true)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', fn (Blueprint $table) => $table->dropColumn('show_name_with_logo'));
    }
};
