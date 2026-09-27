<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TeamMember;
use App\Notifications\Alerts;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = 'Remind each team member of their open tasks due today or overdue';

    public function handle(): int
    {
        $endOfToday = now(config('app.business_timezone'))->endOfDay();
        $sent = 0;

        TeamMember::query()->with('user')->each(function (TeamMember $teamMember) use ($endOfToday, &$sent): void {
            $tasks = Task::query()
                ->involving($teamMember)
                ->where('status', '!=', 'completed')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $endOfToday)
                ->orderBy('due_at')
                ->get();

            if ($tasks->isNotEmpty()) {
                Alerts::dailyTaskReminder($teamMember, $tasks);
                $sent++;
            }
        });

        $this->info("Sent task reminders to {$sent} team ".str('member')->plural($sent).'.');

        return self::SUCCESS;
    }
}
