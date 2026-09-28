<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\TeamMember;
use App\Notifications\Alerts;
use Illuminate\Console\Command;

class SendSiteReportReminders extends Command
{
    protected $signature = 'site:send-reminders';

    protected $description = 'Remind the team of projects in execution that have no site report today';

    public function handle(): int
    {
        $today = now(config('app.business_timezone'))->toDateString();
        $reminded = 0;

        Project::query()
            ->where('status', 'execution')
            ->whereDoesntHave('siteReports', fn ($query) => $query->whereDate('date', $today))
            ->with('teamMembers.user')
            ->each(function (Project $project) use (&$reminded): void {
                $team = $project->teamMembers->filter(fn (TeamMember $member): bool => $member->user !== null);

                if ($team->isNotEmpty()) {
                    Alerts::siteReportReminder($project, $team);
                    $reminded++;
                }
            });

        $this->info("Sent site report reminders for {$reminded} ".str('project')->plural($reminded).'.');

        return self::SUCCESS;
    }
}
