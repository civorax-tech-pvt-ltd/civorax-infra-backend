@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Labourer> $labourers */
    $due = $labourers->sum(fn ($l) => max(0, (float) $l->account_balance));
    $advance = $labourers->sum(fn ($l) => max(0, -(float) $l->account_balance));
    $money = fn (float $amount): string => number_format(abs($amount), 2);
@endphp

<div class="lg">
    <div class="lg-cards">
        <div class="lg-card is-due"><span>Wages due to the gang</span><b>Rs {{ $money($due) }}</b><small>{{ $labourers->filter(fn ($l) => $l->account_balance > 0)->count() }} labourers</small></div>
        <div class="lg-card is-adv"><span>Advances held by the gang</span><b>Rs {{ $money($advance) }}</b><small>{{ $labourers->filter(fn ($l) => $l->account_balance < 0)->count() }} labourers</small></div>
        <div class="lg-card"><span>Net payable</span><b>Rs {{ $money($due - $advance) }}</b><small>{{ $due - $advance >= 0 ? 'if settled as a group' : 'gang owes back' }}</small></div>
    </div>

    <h3 style="font-weight:700;margin:6px 0;">Labourers</h3>
    <div class="lg-scroll">
        <table class="lg-table">
            <thead><tr><th class="l">Name</th><th class="l">Work type</th><th>Rate/day</th><th>Wages due (Rs)</th><th>Advance held (Rs)</th></tr></thead>
            <tbody>
                @forelse ($labourers as $l)
                    <tr>
                        <td class="l">{{ $l->name }}@if ($l->father_name)<br><small>s/o {{ $l->father_name }}</small>@endif</td>
                        <td class="l">{{ $l->workTypeLabel() }}</td>
                        <td>{{ number_format((float) $l->daily_wage) }}</td>
                        <td class="due">{{ $l->account_balance > 0 ? $money($l->account_balance) : '' }}</td>
                        <td class="adv">{{ $l->account_balance < 0 ? $money($l->account_balance) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="lg-muted">No labourers linked to this naike.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h3 style="font-weight:700;margin:16px 0 6px;">Money handed to the naike</h3>
    <div class="lg-scroll">
        <table class="lg-table">
            <thead><tr><th>Date</th><th class="l">For labourer</th><th class="l">Site</th><th class="l">Type</th><th>Amount (Rs)</th></tr></thead>
            <tbody>
                @forelse ($payments as $p)
                    <tr>
                        <td class="nw">{{ $p->paid_on->format('M j, Y') }}</td>
                        <td class="l">{{ $p->labourer?->name }}</td>
                        <td class="l">{{ $p->project?->title }}</td>
                        <td class="l">{{ \App\Models\WagePayment::TYPES[$p->type] ?? $p->type }}<br><small>{{ \App\Models\WagePayment::METHODS[$p->method] ?? $p->method }}@if ($p->reference) · ref {{ $p->reference }}@endif</small></td>
                        <td>{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="lg-muted">Nothing handed over yet.</td></tr>
                @endforelse
            </tbody>
            @if ($payments->isNotEmpty())
                <tfoot><tr><td colspan="4" class="l"><b>Total handed over</b></td><td><b>{{ number_format((float) $payments->sum('amount'), 2) }}</b></td></tr></tfoot>
            @endif
        </table>
    </div>
</div>

@include('site.ledger-styles')
