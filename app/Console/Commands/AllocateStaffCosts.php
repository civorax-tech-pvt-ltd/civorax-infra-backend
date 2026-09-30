<?php

namespace App\Console\Commands;

use App\Models\StaffCostAllocation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AllocateStaffCosts extends Command
{
    protected $signature = 'costs:allocate-staff {month? : Any date in the month to (re)calculate; default this and last month}';

    protected $description = 'Charge salaried staff to projects by GPS attendance days (saved before daily detail is pruned)';

    public function handle(): int
    {
        $months = $this->argument('month')
            ? [Carbon::parse($this->argument('month'))]
            : [now(config('app.business_timezone'))->subMonthNoOverflow(), now(config('app.business_timezone'))];

        foreach ($months as $month) {
            $rows = StaffCostAllocation::allocateMonth($month);
            $this->info("{$month->format('F Y')}: {$rows} allocation ".str('row')->plural($rows).'.');
        }

        return self::SUCCESS;
    }
}
