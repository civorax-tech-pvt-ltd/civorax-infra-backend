<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\MusterRoll;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable A4-landscape muster roll; the browser's "Save as PDF" turns it into a PDF.
 */
class MusterRollPrintController extends Controller
{
    public function __invoke(Request $request, MusterRoll $musterRoll): View
    {
        $allowed = Project::query()
            ->whereKey($musterRoll->project_id)
            ->siteAccessibleBy($request->user())
            ->exists();

        abort_unless($allowed, 403);

        return view('site.muster-roll-print', ['roll' => $musterRoll->load('project', 'preparer', 'approver')]);
    }
}
