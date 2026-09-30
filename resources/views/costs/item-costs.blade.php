@php
    /** @var \App\Models\BoqItemCostReport $itemReport */
    $rows = $itemReport->rows();
    $rs = fn (float $amount): string => number_format($amount);
    $dot = ['green' => '#16a34a', 'amber' => '#d97706', 'red' => '#dc2626', 'none' => '#9ca3af'];
@endphp

<div class="cr">
    <div style="overflow-x:auto;">
        <table class="cr-table">
            <thead>
                <tr>
                    <th>BOQ item</th><th>Planned (Rs)</th><th>Done</th><th>Earned value</th>
                    <th>Actual cost</th><th>Variance</th><th>Projected at completion</th><th>Tagged coverage</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $dot[$row['status']] }};margin-right:4px;"></span>
                            <b>{{ $row['item']->label() }}</b>
                            <br><small>
                                direct {{ $rs($row['direct']) }} · issued {{ $rs($row['issued']) }}
                                @if ($row['estimated'] > 0) · <i>estimated {{ $rs($row['estimated']) }}</i> ({{ implode(', ', $row['estimates']) }}) @endif
                            </small>
                        </td>
                        <td>{{ $rs($row['planned']) }}</td>
                        <td>{{ $row['progress'] }}%</td>
                        <td>{{ $rs($row['earned']) }}</td>
                        <td>{{ $rs($row['actual']) }}</td>
                        <td style="color:{{ $row['variance'] < 0 ? '#b91c1c' : '#15803d' }};font-weight:600;">{{ $row['variance'] >= 0 ? '+' : '' }}{{ $rs($row['variance']) }}</td>
                        <td>{{ $row['projected'] === null ? '—' : $rs($row['projected']) }}</td>
                        <td>
                            @if ($row['coverage'] === null) —
                            @else
                                {{ $row['coverage'] }}%
                                @if ($row['coverage'] < 60)<br><small style="color:#b45309;">mostly estimated: a saving here may be false</small>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cr-muted">No BOQ items yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:12px;color:#6b7280;margin-top:6px;">
        Earned value = executed quantity × BOQ rate. Actual = costs tagged to the item + approved material issues + estimates from norms (for materials not issued).
        Variance = earned − actual (negative = over cost). Costs not tagged to any item (Rs {{ $rs($itemReport->untaggedCost()) }}) stay in the project total above.
    </p>
</div>
