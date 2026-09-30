@php $rs = fn ($amount) => 'Rs '.number_format((float) $amount, 2); @endphp

<div style="display:flex;flex-direction:column;gap:.8rem;font-size:.9rem;">
    @isset($bill)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem;">
            <div><small style="color:rgb(107 114 128)">Vendor</small><br><b>{{ $bill->vendor?->name }}</b> @if ($bill->vendor_pan_vat)<br><small>PAN/VAT {{ $bill->vendor_pan_vat }}</small>@endif</div>
            <div><small style="color:rgb(107 114 128)">Bill</small><br><b>{{ \App\Models\PurchaseBill::TYPES[$bill->bill_type] }} {{ $bill->bill_no }}</b><br><small>{{ $bill->bill_date->format('M j, Y') }}</small></div>
            <div><small style="color:rgb(107 114 128)">Amount</small><br>{{ $rs($bill->base_amount) }} @if ($bill->bill_type === 'vat') + VAT {{ $rs($bill->vat_amount) }} @endif<br><b>{{ $rs($bill->total_amount) }}</b></div>
            <div><small style="color:rgb(107 114 128)">Cost to project</small><br><b>{{ $rs($bill->ledgerCost()) }}</b><br><small>{{ $bill->vat_claimable ? 'VAT claimable' : ($bill->bill_type === 'vat' ? 'VAT not claimable (in cost)' : 'PAN bill') }}</small></div>
            <div><small style="color:rgb(107 114 128)">Paid</small><br><b>{{ $bill->paidStatus() }}</b><br><small>{{ $rs($bill->amountPaid()) }} paid</small></div>
        </div>
        @if ($bill->isFlagged())
            <div style="padding:.5rem .7rem;border-radius:.5rem;background:#fee2e2;color:#991b1b;">Not made out to the company. Kept out of project cost until a super admin approves it.</div>
        @endif
        @if ($bill->items)
            <table style="width:100%;border-collapse:collapse;">
                <tr style="color:rgb(107 114 128);font-size:.8rem;text-align:left;"><th>Item</th><th style="text-align:right">Quantity</th></tr>
                @foreach ($bill->items as $line)
                    <tr style="border-top:1px solid rgb(0 0 0 / .07);"><td>{{ $line['item'] ?? '' }}</td><td style="text-align:right">{{ $line['quantity'] ?? '' }} {{ $line['unit'] ?? '' }}</td></tr>
                @endforeach
            </table>
        @endif
        @if ($bill->description)<div>{{ $bill->description }}</div>@endif
    @endisset

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.5rem;">
        @forelse ($urls as $url)
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display:block;border-radius:.5rem;overflow:hidden;background:rgb(0 0 0 / .05);aspect-ratio:3/4;">
                <img src="{{ $url }}" alt="Receipt" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
            </a>
        @empty
            <div style="color:rgb(107 114 128);">No photo attached.</div>
        @endforelse
    </div>
</div>
