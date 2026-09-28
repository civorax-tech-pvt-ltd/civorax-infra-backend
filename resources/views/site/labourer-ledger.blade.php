@php
    /** @var \App\Models\Labourer $labourer */
    $overall = $labourer->balance();
    $describe = fn (float $balance): string => $balance > 0 ? 'due to labourer' : ($balance < 0 ? 'advance held' : 'clear');
    $money = fn (float $amount): string => number_format(abs($amount), 2);
@endphp

<div class="lg">
    <div class="lg-cards">
        <div class="lg-card {{ $overall > 0 ? 'is-due' : ($overall < 0 ? 'is-adv' : '') }}">
            <span>Balance, all sites</span>
            <b>Rs {{ $money($overall) }}</b>
            <small>{{ $describe($overall) }}</small>
        </div>
        <div class="lg-card"><span>Earned{{ $filtered ? ' (this site)' : '' }}</span><b>Rs {{ $money($ledger['credit']) }}</b><small>wages + advances returned</small></div>
        <div class="lg-card"><span>Paid{{ $filtered ? ' (this site)' : '' }}</span><b>Rs {{ $money($ledger['debit']) }}</b><small>wages + advances given</small></div>
    </div>

    <div class="lg-scroll">
        <table class="lg-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="l">Site</th>
                    <th class="l">Entry</th>
                    <th>Earned / returned (Rs)</th>
                    <th>Paid (Rs)</th>
                    <th>Balance (Rs)</th>
                </tr>
            </thead>
            <tbody>
                @if ($ledger['opening'] != 0)
                    <tr class="lg-muted">
                        <td colspan="5" class="l">Opening balance</td>
                        <td>{{ $money($ledger['opening']) }} <small>{{ $ledger['opening'] > 0 ? 'due' : 'adv' }}</small></td>
                    </tr>
                @endif
                @forelse ($ledger['rows'] as $row)
                    <tr>
                        <td class="nw">{{ $row['date']->format('M j, Y') }}<br><small>{{ \App\Models\MusterRoll::bsDate($row['date']) }}</small></td>
                        <td class="l">{{ $row['site'] ?? '—' }}</td>
                        <td class="l"><b>{{ $row['entry'] }}</b>@if ($row['detail'])<br><small>{{ $row['detail'] }}</small>@endif</td>
                        <td>{{ $row['credit'] ? $money($row['credit']) : '' }}</td>
                        <td>{{ $row['debit'] ? $money($row['debit']) : '' }}</td>
                        <td class="nw {{ $row['balance'] > 0 ? 'due' : ($row['balance'] < 0 ? 'adv' : '') }}">{{ $money($row['balance']) }} <small>{{ $row['balance'] > 0 ? 'due' : ($row['balance'] < 0 ? 'adv' : '') }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="lg-muted">No wages or payments yet. Wages appear here once a muster roll is approved.</td></tr>
                @endforelse
            </tbody>
            @if (count($ledger['rows']))
                <tfoot>
                    <tr>
                        <td colspan="3" class="l"><b>Closing balance{{ $filtered ? ' (this site)' : '' }}</b></td>
                        <td><b>{{ $money($ledger['credit']) }}</b></td>
                        <td><b>{{ $money($ledger['debit']) }}</b></td>
                        <td class="{{ $ledger['closing'] > 0 ? 'due' : ($ledger['closing'] < 0 ? 'adv' : '') }}"><b>{{ $money($ledger['closing']) }}</b> <small>{{ $describe($ledger['closing']) }}</small></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="lg-note">One account per labourer across all sites: an advance given at any site is recovered from wages earned at any site. "due" = we owe the labourer; "adv" = the labourer holds an advance.</p>
</div>

@include('site.ledger-styles')
