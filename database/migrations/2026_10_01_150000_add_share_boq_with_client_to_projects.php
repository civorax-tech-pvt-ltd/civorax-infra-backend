<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Off by default: the BOQ stays internal unless the admin shares its progress (quantities only, never rates).
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('share_boq_with_client')->default(false)->after('track_item_costs');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('share_boq_with_client'));
    }
};
