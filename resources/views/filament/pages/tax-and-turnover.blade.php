<x-filament-panels::page>
    @php
        $report = $this->report();
        $rs = fn (float $amount): string => 'Rs '.number_format($amount, 2);
        $limit = $report->limit();
        $status = $report->limitStatus();
        $receipts = $report->receipts();
        $projected = $report->projectedYearEnd();
        $vat = $report->vatSummary();
        $warn = $report->settings->vat_warning_percent ?: 80;
        $badge = ['ok' => ['#dcfce7', '#166534', 'Below the warning level'], 'warning' => ['#fef3c7', '#92400e', "Above {$warn}% of the limit"], 'over' => ['#fee2e2', '#991b1b', 'Over the registration limit']];
    @endphp

    <div style="display:flex;align-items:center;gap:.6rem;">
        <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="previousYear" label="Previous year" />
        <b style="font-size:1.1rem;">{{ $report->label() }}</b>
        <span style="color:rgb(107 114 128);font-size:.85rem;">{{ $report->start->format('M j, Y') }} – {{ $report->end->format('M j, Y') }}</span>
        <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="nextYear" label="Next year" />
    </div>

    <div class="cr">
        <div class="cr-cards">
            <div><span>Received from clients & students</span><b>{{ $rs($receipts) }}</b>
                <small>@if ($status['actual']) {{ $badge[$status['actual']][2] }} @else set the limit in Company & Tax Settings @endif</small></div>
            <div><span>Projected year end</span><b>{{ $rs($projected) }}</b><small>if every signed contract is paid this year</small></div>
            <div><span>VAT registration limit</span><b>{{ $limit === null ? '—' : $rs($limit) }}</b><small>{{ $limit ? round($receipts / $limit * 100, 1).'% reached' : 'not set' }}</small></div>
            <div><span>Purchases on VAT bills</span><b>{{ $rs($report->vatBillPurchases()) }}</b><small>supplier VAT you could claim if registered</small></div>
        </div>

        @foreach (['projected' => 'Projection', 'actual' => 'Receipts so far'] as $key => $label)
            @if (in_array($status[$key], ['warning', 'over'], true))
                @php [$bg, $fg, $text] = $badge[$status[$key]]; @endphp
                <div style="margin-top:12px;padding:10px 14px;border-radius:10px;background:{{ $bg }};color:{{ $fg }};">
                    <b>{{ $label }}: {{ $text }}.</b> Talk to your accountant about VAT registration. Registering here is only a settings change; past bills keep their treatment.
                </div>
                @break
            @endif
        @endforeach

        <h3>VAT summary</h3>
        @if (! $report->settings->vat_registered)
            <p style="color:#6b7280;font-size:13px;">The company is PAN-only, so there is no VAT to file. Each project's cost page shows its profit "if VAT registered" for comparison.</p>
        @elseif (! $vat)
            <p style="color:#6b7280;font-size:13px;">No months since the registration date in this fiscal year.</p>
        @else
            <table class="cr-table">
                <thead><tr><th>Month</th><th>Output VAT</th><th>Input VAT claimed</th><th>Net</th><th>Payable</th><th>Credit carried forward</th></tr></thead>
                <tbody>
                    @foreach ($vat as $row)
                        <tr>
                            <td>{{ $row['month'] }}</td>
                            <td>{{ $rs($row['output']) }}</td>
                            <td>{{ $rs($row['input']) }}</td>
                            <td>{{ $rs($row['net']) }}</td>
                            <td><b>{{ $rs($row['payable']) }}</b></td>
                            <td>{{ $rs($row['credit']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="color:#6b7280;font-size:12px;margin-top:6px;">Output VAT = VAT inside amounts received ({{ $report->settings->vat_rate }}% of the pre-VAT price). Input VAT = VAT on approved supplier bills dated on/after registration. Confirm figures with your accountant before filing.</p>
        @endif
    </div>

    @include('costs.report-styles')
</x-filament-panels::page>
