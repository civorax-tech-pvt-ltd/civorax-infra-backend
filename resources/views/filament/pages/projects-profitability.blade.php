<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $rs = fn (float $amount): string => 'Rs '.number_format($amount);
        $health = ['green' => ['#dcfce7', '#166534', 'On track'], 'amber' => ['#fef3c7', '#92400e', 'Watch'], 'red' => ['#fee2e2', '#991b1b', 'At risk'], 'unknown' => ['#f3f4f6', '#374151', 'No budget']];
        $totalContract = $rows->sum('contract');
        $totalProfit = $rows->sum('profit');
        $losing = $rows->filter(fn ($r) => $r['profit'] < 0)->count();
    @endphp

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        @foreach (['active' => 'Running', 'completed' => 'Completed', 'all' => 'All'] as $key => $label)
            <x-filament::button size="sm" :color="$scope === $key ? 'primary' : 'gray'" wire:click="$set('scope', '{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>

    <div class="cr">
        <div class="cr-cards">
            <div><span>Projects</span><b>{{ $rows->count() }}</b><small>{{ $losing }} projected to lose money</small></div>
            <div><span>Contract value</span><b>{{ $rs($totalContract) }}</b></div>
            <div><span>Cost so far</span><b>{{ $rs($rows->sum('cost')) }}</b></div>
            <div class="{{ $totalProfit < 0 ? 'bad' : 'good' }}"><span>Projected profit</span><b>{{ $rs($totalProfit) }}</b><small>{{ $totalContract > 0 ? round($totalProfit / $totalContract * 100, 1).'% overall margin' : '' }}</small></div>
            <div><span>Cash in hand</span><b>{{ $rs($rows->sum('cash')) }}</b><small>received − paid out</small></div>
        </div>

        <div style="overflow-x:auto;margin-top:14px;">
            <table class="cr-table">
                <thead>
                    <tr><th>Project</th><th>Status</th><th>Contract</th><th>Budget</th><th>Cost so far</th><th>Projected cost</th><th>Projected profit</th><th>Margin</th><th>Work done</th><th>Health</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows->sortBy('profit') as $row)
                        @php [$bg, $fg, $label] = $health[$row['health']]; @endphp
                        <tr>
                            <td><a href="{{ $row['url'] }}" style="font-weight:600;color:rgb(var(--primary-600));">{{ $row['project']->title }}</a><br><small>{{ $row['project']->client?->contact_person }}</small></td>
                            <td>{{ \App\Models\Project::STATUSES[$row['project']->status] ?? $row['project']->status }}</td>
                            <td class="nw">{{ $rs($row['contract']) }}</td>
                            <td class="nw">{{ $row['budget'] > 0 ? $rs($row['budget']) : '—' }}</td>
                            <td class="nw">{{ $rs($row['cost']) }}</td>
                            <td class="nw">{{ $rs($row['projected_cost']) }}</td>
                            <td class="nw" style="color:{{ $row['profit'] < 0 ? '#b91c1c' : '#15803d' }};font-weight:700;">{{ $rs($row['profit']) }}@if ($row['staff'] > 0)<br><small>after staff {{ $rs($row['profit'] - $row['staff']) }}</small>@endif</td>
                            <td>{{ $row['margin'] === null ? '—' : $row['margin'].'%' }}</td>
                            <td>{{ $row['work'] }}%</td>
                            <td><span style="background:{{ $bg }};color:{{ $fg }};padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;">{{ $label }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="cr-muted">No projects.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p style="font-size:12px;color:#6b7280;margin-top:6px;">Sorted with the weakest projects first. Projected profit = contract value − (cost so far + committed + estimated cost to finish).</p>
    </div>

    @include('costs.report-styles')
</x-filament-panels::page>
