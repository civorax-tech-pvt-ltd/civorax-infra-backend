@php
    /** @var \App\Models\ProjectCostReport $report */
    $project = $report->project;
    $rs = fn (float $amount): string => 'Rs '.number_format($amount, 2);
    $health = $report->health();
    $work = $report->workComplete();
    $spent = $report->percentOfBudget();
    $healthStyle = [
        'green' => ['#dcfce7', '#166534', 'On track'],
        'amber' => ['#fef3c7', '#92400e', 'Watch'],
        'red' => ['#fee2e2', '#991b1b', 'At risk'],
        'unknown' => ['#f3f4f6', '#374151', 'Not enough data'],
    ][$health['status']];
    $profit = $report->projectedProfit();
    $finalVsBudget = $report->projectedFinalCost() - $report->budgetTotal();
@endphp

<div class="cr">
    <div class="cr-health" style="background: {{ $healthStyle[0] }}; color: {{ $healthStyle[1] }};">
        <b>{{ $healthStyle[2] }}</b>
        <span>Work complete {{ $work['percent'] }}% ({{ $work['source'] }}) · Budget spent {{ $spent === null ? '—' : $spent.'%' }}</span>
        @if ($health['reasons'])
            <ul>@foreach ($health['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        @endif
    </div>

    <div class="cr-cards">
        <div><span>Contract value</span><b>{{ $rs($report->contractValue()) }}</b><small>{{ $project->fee === null ? 'Fee not set yet' : 'Accepted quotation / project fee' }}</small></div>
        <div><span>Cost budget</span><b>{{ $rs($report->budgetTotal()) }}</b><small>Planned profit {{ $rs($report->plannedProfit()) }}</small></div>
        <div><span>Cost so far</span><b>{{ $rs($report->costSoFar()) }}</b><small>{{ $spent === null ? 'No budget set' : $spent.'% of budget' }}</small></div>
        <div class="{{ $profit < 0 ? 'bad' : 'good' }}"><span>Projected profit</span><b>{{ $rs($profit) }}</b><small>{{ $report->projectedMargin() === null ? '—' : $report->projectedMargin().'% margin' }}</small></div>
        <div><span>Cash in hand</span><b>{{ $rs($report->cash()) }}</b><small>received − paid out</small></div>
    </div>

    <h3>Profit or loss</h3>
    <table class="cr-table">
        <tr><td>Contract value @if ($report->variationsTotal() > 0)<small>(fee {{ $rs((float) $project->fee) }} + approved extra work {{ $rs($report->variationsTotal()) }})</small>@endif</td><td>{{ $rs($report->contractValue()) }}</td></tr>
        <tr><td>Cost so far <small>(approved ledger{{ $spent === null ? '' : ', '.$spent.'% of budget' }})</small></td><td>{{ $rs($report->costSoFar()) }}</td></tr>
        <tr><td>Committed, not yet billed <small>(work orders, POs)</small></td><td>{{ $rs($report->committed()) }}</td></tr>
        <tr><td>Estimated cost to finish <small>({{ $report->costToFinishIsOverridden() ? 'entered estimate' : 'budget left after costs and commitments' }})</small></td><td>{{ $rs($report->costToFinish()) }}</td></tr>
        <tr class="cr-total"><td>Projected final cost <small>(budget {{ $rs($report->budgetTotal()) }}{{ $report->budgetTotal() > 0 && abs($finalVsBudget) >= 0.01 ? ', '.$rs(abs($finalVsBudget)).($finalVsBudget > 0 ? ' over' : ' under') : '' }})</small></td><td>{{ $rs($report->projectedFinalCost()) }}</td></tr>
        <tr class="cr-total {{ $profit < 0 ? 'bad' : 'good' }}"><td>Projected profit <small>{{ $report->projectedMargin() === null ? '' : '('.$report->projectedMargin().'% margin)' }}</small></td><td>{{ $rs($profit) }}</td></tr>
        @if ($report->staffCost() > 0)
            <tr><td>Staff cost <small>(salaries shared by GPS attendance days)</small></td><td>{{ $rs($report->staffCost()) }}</td></tr>
            <tr class="cr-total {{ $report->profitAfterStaffCost() < 0 ? 'bad' : 'good' }}"><td>Profit after staff cost</td><td>{{ $rs($report->profitAfterStaffCost()) }}</td></tr>
        @endif
        <tr><td>Cash: received {{ $rs($report->received()) }} − paid out {{ $rs($report->paidOut()) }}</td><td>{{ $rs($report->cash()) }}</td></tr>
        <tr><td>VAT paid to suppliers (not claimable) <small>(included in cost)</small></td><td>{{ $rs($report->vatNotClaimable()) }}</td></tr>
        @unless (\App\Models\CompanySetting::current()->vat_registered)
            @php $ifVat = $report->profitIfVatRegistered(); @endphp
            <tr><td>If VAT registered <small>(revenue {{ $rs($ifVat['revenue']) }} after output VAT, cost {{ $rs($ifVat['cost']) }} without supplier VAT; price basis: {{ \App\Models\Project::PRICE_BASES[$project->price_basis] ?? $project->price_basis }})</small></td><td>{{ $rs($ifVat['profit']) }}{{ $ifVat['margin'] === null ? '' : ' · '.$ifVat['margin'].'%' }}</td></tr>
        @endunless
    </table>

    <h3>Budget vs actual</h3>
    <table class="cr-table cr-cats">
        <thead><tr><th>Category</th><th>Budget</th><th>Actual</th><th>Used</th></tr></thead>
        <tbody>
            @foreach ($report->categories() as $category)
                <tr class="flag-{{ $category['flag'] }}">
                    <td>{{ $category['label'] }}
                        @if ($category['flag'] === 'over') <em>over budget</em>
                        @elseif ($category['flag'] === 'warning') <em>80%+ used</em>
                        @elseif ($category['flag'] === 'unbudgeted') <em>no budget</em>
                        @endif
                    </td>
                    <td>{{ $category['budget'] > 0 ? $rs($category['budget']) : '—' }}</td>
                    <td>{{ $category['actual'] > 0 ? $rs($category['actual']) : '—' }}</td>
                    <td class="cr-bar-cell">
                        @if ($category['percent'] !== null)
                            <div class="cr-bar"><i style="width: {{ min(100, $category['percent']) }}%"></i></div>
                            <span>{{ $category['percent'] }}%</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($project->track_item_costs)
        <h3>Cost per BOQ item</h3>
        @include('costs.item-costs', ['itemReport' => $project->itemCostReport()])
    @endif

    @php $materialRows = $project->materialReport()->rows(); @endphp
    @if ($materialRows)
        <h3>Key materials</h3>
        @include('costs.materials', ['rows' => $materialRows, 'work' => $work])
    @endif

    @if ($showLedger ?? false)
        @php $entries = $project->costs()->approved()->latest('entry_date')->latest('id')->limit(50)->get(); @endphp
        <h3>Cost ledger <small>(latest {{ $entries->count() }})</small></h3>
        <table class="cr-table">
            <thead><tr><th>Date</th><th>Category</th><th>Entry</th><th>Source</th><th>Amount</th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="nw">{{ $entry->entry_date->format('M j, Y') }}</td>
                        <td>{{ \App\Models\ProjectCost::CATEGORIES[$entry->category] ?? $entry->category }}</td>
                        <td>{{ $entry->description }}</td>
                        <td>{{ $entry->sourceLabel() }}</td>
                        <td>{{ $rs((float) $entry->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="cr-muted">No costs yet. Approved muster rolls, purchase bills and petty-cash claims appear here automatically.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>

@include('costs.report-styles')
