<?php

use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('projects:refresh-progress', function () {
    ProjectMilestone::query()->with('project')->each(fn (ProjectMilestone $milestone) => $milestone->refreshProgress());
    Project::query()->doesntHave('milestones')->each(fn (Project $project) => $project->refreshProgress());

    $this->info('Recalculated progress for '.Project::count().' projects.');
})->purpose('Recalculate milestone and project progress from tasks');
