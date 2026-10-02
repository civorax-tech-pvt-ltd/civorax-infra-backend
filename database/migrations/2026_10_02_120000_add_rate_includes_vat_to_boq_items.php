<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each project BOQ line records whether its own rate includes the supplier's VAT (copied from the library
 * item when added, changeable per project).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->boolean('rate_includes_vat')->default(false)->after('rate');
        });

        DB::table('boq_items')
            ->whereIn('master_item_id', DB::table('boq_master_items')->where('rate_includes_vat', true)->select('id'))
            ->update(['rate_includes_vat' => true]);
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn('rate_includes_vat');
        });
    }
};
