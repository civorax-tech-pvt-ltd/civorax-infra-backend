<?php

namespace App\Console\Commands;

use App\Filament\Pages\SiteMaterials;
use App\Models\MaterialDelivery;
use App\Models\MaterialStockCount;
use App\Models\Project;
use App\Models\TeamMember;
use App\Notifications\Alert;
use Illuminate\Console\Command;

class SendStockCountReminders extends Command
{
    protected $signature = 'materials:stock-count-reminders';

    protected $description = 'Remind site teams to count key materials left on site (monthly)';

    public function handle(): int
    {
        $monthStart = now(config('app.business_timezone'))->startOfMonth()->toDateString();
        $reminded = 0;

        Project::query()
            ->where('status', 'execution')
            ->whereIn('id', MaterialDelivery::query()->select('project_id'))
            ->whereNotIn('id', MaterialStockCount::query()->whereDate('counted_on', '>=', $monthStart)->select('project_id'))
            ->with('teamMembers.user')
            ->each(function (Project $project) use (&$reminded): void {
                Alert::send(
                    $project->teamMembers->map(fn (TeamMember $member) => $member->user),
                    'Monthly stock count due',
                    "{$project->title}: count cement, rod, bricks, sand and other key materials left on site.",
                    SiteMaterials::getUrl(['project' => $project->id], panel: 'team'),
                    'heroicon-o-clipboard-document-check',
                    'warning',
                );
                $reminded++;
            });

        $this->info("Sent stock count reminders for {$reminded} ".str('project')->plural($reminded).'.');

        return self::SUCCESS;
    }
}
