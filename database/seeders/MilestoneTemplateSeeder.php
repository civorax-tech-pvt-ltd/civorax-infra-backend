<?php

namespace Database\Seeders;

use App\Models\ProjectType;
use Illuminate\Database\Seeder;

/**
 * Starter milestone templates per project type. Skips any type that already has templates,
 * so it is safe to re-run and never overwrites templates edited in the admin panel.
 */
class MilestoneTemplateSeeder extends Seeder
{
    /**
     * Project type name => milestones as [title, phase, billing %, [[task, weight], ...]].
     *
     * @var array<string, list<array{0: string, 1: string, 2: int, 3: list<array{0: string, 1: int}>}>>
     */
    protected const TEMPLATES = [
        'Residential' => [
            ['Planning & Survey', 'planning', 10, [['Site visit & plot measurement', 1], ['Client requirement meeting', 1], ['Soil test coordination', 1]]],
            ['Architectural Design', 'designing', 30, [['Concept design', 2], ['2D floor plans', 3], ['Elevations & sections', 2], ['3D exterior design', 3]]],
            ['Structural Design & Estimation', 'designing', 25, [['Structural analysis & design', 3], ['Structural drawings', 2], ['BOQ preparation', 2], ['Cost estimation', 2]]],
            ['Municipality Approval', 'awaiting_approval', 15, [['Prepare naksa pass documents', 2], ['Submit to municipality', 1], ['Resolve objections', 1], ['Receive building permit', 1]]],
            ['Construction Supervision', 'execution', 20, [['Foundation supervision', 2], ['Frame structure supervision', 3], ['Finishing supervision', 2], ['Final inspection & handover', 1]]],
        ],
        'Commercial' => [
            ['Planning & Feasibility', 'planning', 10, [['Site visit & survey', 1], ['Requirement & feasibility study', 2], ['Soil test coordination', 1]]],
            ['Architectural Design', 'designing', 25, [['Concept design', 2], ['2D floor plans', 3], ['Elevations & sections', 2], ['3D exterior design', 3]]],
            ['Structural & MEP Design', 'designing', 25, [['Structural analysis & design', 3], ['Structural drawings', 2], ['Electrical & plumbing layout', 2], ['Fire safety plan', 1]]],
            ['Estimation & Approval', 'awaiting_approval', 15, [['BOQ preparation', 2], ['Cost estimation', 2], ['Municipality submission', 1], ['Receive building permit', 1]]],
            ['Construction Supervision', 'execution', 25, [['Foundation supervision', 2], ['Frame structure supervision', 3], ['MEP installation supervision', 2], ['Final inspection & handover', 1]]],
        ],
        'Interior' => [
            ['Site Survey & Brief', 'planning', 10, [['Site measurement', 1], ['Requirement & budget meeting', 1], ['Mood board & style direction', 1]]],
            ['Interior Design', 'designing', 40, [['2D furniture layout', 2], ['3D renders', 3], ['False ceiling & lighting plan', 2], ['Material & finish selection', 1]]],
            ['Estimation & Approval', 'awaiting_approval', 10, [['BOQ & cost estimate', 2], ['Client design approval', 1]]],
            ['Execution', 'execution', 40, [['False ceiling & electrical work', 2], ['Flooring & tiling', 2], ['Furniture & fixtures', 3], ['Painting & finishing', 2], ['Final handover', 1]]],
        ],
        'Renovation' => [
            ['Assessment', 'planning', 15, [['Site inspection', 1], ['Condition report', 2], ['Requirement meeting', 1]]],
            ['Design & Estimate', 'designing', 35, [['Renovation drawings', 3], ['3D visualization', 2], ['BOQ & cost estimate', 2]]],
            ['Execution', 'execution', 50, [['Demolition & repair', 2], ['Civil works', 3], ['Finishing works', 2], ['Final handover', 1]]],
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $typeName => $milestones) {
            $projectType = ProjectType::query()->where('name', $typeName)->first();

            if ($projectType === null || $projectType->milestoneTemplates()->exists()) {
                continue;
            }

            foreach ($milestones as $sequence => [$title, $phase, $billingPercent, $tasks]) {
                $template = $projectType->milestoneTemplates()->create([
                    'title' => $title,
                    'phase' => $phase,
                    'sequence' => $sequence + 1,
                    'billing_percent' => $billingPercent,
                ]);

                foreach ($tasks as $sort => [$taskTitle, $weight]) {
                    $template->tasks()->create(['title' => $taskTitle, 'weight' => $weight, 'sort' => $sort + 1]);
                }
            }

            $this->command?->info("Added milestone template for {$typeName}.");
        }
    }
}
