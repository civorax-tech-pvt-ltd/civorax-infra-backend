@php
    /** @var list<array<string, mixed>> $rows */
    $q = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2), '0'), '.');
@endphp

<div class="cr">
    <table class="cr-table">
        <thead>
            <tr>
                <th>Material</th><th>Planned</th><th>Purchased</th><th>Received</th><th>Transfers</th>
                <th>Left (last count)</th><th>Used</th><th>Expected use at {{ $work['percent'] }}%</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td><b>{{ $row['material']->name }}</b> <small>{{ $row['material']->unit }}</small>
                        @if ($row['flag'])<br><em style="font-style:normal;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;background:#fef3c7;color:#92400e;">{{ $row['flag'] }}</em>@endif
                    </td>
                    <td>{{ $q($row['planned']) }}</td>
                    <td>{{ $q($row['purchased']) }}</td>
                    <td>{{ $q($row['received']) }}</td>
                    <td>@if ($row['transferred_in'] || $row['transferred_out']) +{{ $q($row['transferred_in']) }} / −{{ $q($row['transferred_out']) }} @else — @endif</td>
                    <td>{{ $q($row['left']) }}@if ($row['counted_on'])<br><small>{{ $row['counted_on']->format('M j') }}</small>@endif</td>
                    <td>{{ $q($row['used']) }}</td>
                    <td>{{ $q($row['expected_use']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="cr-muted">No key materials yet. Add planned quantities, name key materials on purchase bill lines, and record deliveries.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p style="font-size:12px;color:#6b7280;margin-top:6px;">Purchased = approved bill lines naming the material · Received = deliveries · Used = received ± transfers − last count · Expected use = planned × work complete ({{ $work['source'] }}).</p>
</div>
