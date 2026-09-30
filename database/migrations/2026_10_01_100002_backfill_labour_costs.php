<?php

use App\Models\MusterRoll;
use App\Models\ProjectCost;
use Illuminate\Database\Migrations\Migration;

/**
 * Muster rolls approved before the cost ledger existed become labour cost entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        MusterRoll::query()->where('status', 'approved')->each(fn (MusterRoll $roll) => ProjectCost::syncMusterRoll($roll));
    }

    public function down(): void
    {
        ProjectCost::query()->where('source_type', 'muster_roll')->delete();
    }
};
