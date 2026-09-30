<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('address');
        });

        // Copied from the student at issue time; shown on the public verification page only, never printed.
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('student_name');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('photo_path'));
    }
};
