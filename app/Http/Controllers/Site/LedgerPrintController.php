<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LabourContractor;
use App\Models\Labourer;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable labourer and naike ledgers, for signing on pay day.
 */
class LedgerPrintController extends Controller
{
    public function labourer(Request $request, Labourer $labourer): View
    {
        $this->authorizeWages($request);

        $projectId = $request->integer('project_id') ?: null;

        return view('site.ledger-print', [
            'title' => "Ledger – {$labourer->name}",
            'labourer' => $labourer->load('contractor'),
            'ledger' => $labourer->ledger($projectId, $request->date('from')?->toDateString(), $request->date('until')?->toDateString()),
            'site' => $projectId ? Project::find($projectId)?->title : null,
            'from' => $request->date('from'),
            'until' => $request->date('until'),
        ]);
    }

    public function contractor(Request $request, LabourContractor $contractor): View
    {
        $this->authorizeWages($request);

        return view('site.naike-ledger-print', [
            'title' => "Naike account – {$contractor->name}",
            'contractor' => $contractor,
            'labourers' => $contractor->labourers()->withBalance()->orderBy('name')->get(),
            'payments' => $contractor->wagePayments()->with(['labourer', 'project'])->latest('paid_on')->limit(100)->get(),
        ]);
    }

    protected function authorizeWages(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->hasSitePower('pay_labour_wages') || $user->hasSitePower('approve_site_records'), 403);
    }
}
