<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The fee is unknown until a quotation is accepted.
            $table->decimal('fee', 14, 2)->nullable()->change();
            $table->date('start_date')->nullable()->after('fee');
        });

        DB::table('projects')->whereNull('start_date')->update(['start_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
