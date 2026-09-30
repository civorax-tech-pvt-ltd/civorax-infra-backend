@php
    /** @var \App\Models\Vendor $vendor */
    $rs = fn (float $amount): string => number_format($amount, 2);
    $confirmation = $vendor->balanceConfirmations()->first();
@endphp

<div class="cr">
    <div class="cr-cards">
        <div><span>Billed (approved bills)</span><b>Rs {{ $rs($vendor->billedTotal()) }}</b><small>bill totals incl. VAT</small></div>
        <div><span>Paid</span><b>Rs {{ $rs($vendor->paidTotal()) }}</b></div>
        <div class="{{ $vendor->outstanding() > 0 ? 'bad' : 'good' }}"><span>Owed now</span><b>Rs {{ $rs($vendor->outstanding()) }}</b></div>
        <div><span>Last confirmation</span><b>{{ $confirmation ? 'Rs '.$rs((float) $confirmation->balance) : '—' }}</b><small>{{ $confirmation ? 'as of '.$confirmation->as_of->format('M j, Y').($confirmation->agreed ? ' · agreed' : ' · disputed') : 'none recorded' }}</small></div>
    </div>

    <table class="cr-table" style="margin-top:14px">
        <thead><tr><th>Date</th><th>Entry</th><th>Site</th><th style="text-align:right">Billed (Rs)</th><th style="text-align:right">Paid (Rs)</th><th>Balance (Rs)</th></tr></thead>
        <tbody>
            @if ($statement['opening'] != 0)
                <tr><td colspan="5">Opening balance</td><td>{{ $rs($statement['opening']) }}</td></tr>
            @endif
            @forelse ($statement['rows'] as $row)
                <tr>
                    <td class="nw">{{ $row['date']->format('M j, Y') }}</td>
                    <td>{{ $row['entry'] }}</td>
                    <td>{{ $row['site'] ?? '—' }}</td>
                    <td style="text-align:right">{{ $row['billed'] ? $rs($row['billed']) : '' }}</td>
                    <td style="text-align:right">{{ $row['paid'] ? $rs($row['paid']) : '' }}</td>
                    <td>{{ $rs($row['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="cr-muted">No approved bills or payments.</td></tr>
            @endforelse
        </tbody>
        @if (count($statement['rows']))
            <tfoot>
                <tr class="cr-total"><td colspan="3">Closing balance owed</td><td style="text-align:right">{{ $rs($statement['billed']) }}</td><td style="text-align:right">{{ $rs($statement['paid']) }}</td><td>{{ $rs($statement['closing']) }}</td></tr>
            </tfoot>
        @endif
    </table>

    @if ($vendor->balanceConfirmations->isNotEmpty())
        <h3>Balance confirmations</h3>
        <table class="cr-table">
            <thead><tr><th>As of</th><th>Result</th><th>Note</th><th>Balance (Rs)</th></tr></thead>
            <tbody>
                @foreach ($vendor->balanceConfirmations as $c)
                    <tr>
                        <td>{{ $c->as_of->format('M j, Y') }}</td>
                        <td>{{ $c->agreed ? 'Agreed' : 'Disputed' }}@if ($c->document_path) · <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($c->document_path) }}" target="_blank" rel="noopener">document</a>@endif</td>
                        <td>{{ $c->note }}</td>
                        <td>{{ $rs((float) $c->balance) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@include('costs.report-styles')
