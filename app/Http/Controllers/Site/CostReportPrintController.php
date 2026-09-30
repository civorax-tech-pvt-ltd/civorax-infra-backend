<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable project cost / final profit & loss statement.
 */
class CostReportPrintController extends Controller
{
    public function __invoke(Request $request, Project $project): View
    {
        abort_unless($request->user()->hasSitePower('view_project_costs'), 403);

        return view('costs.print', ['report' => $project->costReport()]);
    }
}
