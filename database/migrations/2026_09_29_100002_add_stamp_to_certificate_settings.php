<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->string('stamp_path')->nullable()->after('director_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', fn (Blueprint $table) => $table->dropColumn('stamp_path'));
    }
};
