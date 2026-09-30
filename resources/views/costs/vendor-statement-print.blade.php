<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement – {{ $vendor->name }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 16px; color: #111827; background: #fff; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 14px; }
        .toolbar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #374151; }
        .head { text-align: center; margin-bottom: 12px; }
        .head .org { font-size: 17px; font-weight: 700; }
        .head .t { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
        .meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 20px; font-size: 13px; margin-bottom: 12px; }
        .confirm { margin-top: 26px; font-size: 13px; border: 1px dashed #9ca3af; border-radius: 8px; padding: 12px 14px; }
        .sign { display: flex; justify-content: space-between; gap: 30px; margin-top: 44px; font-size: 12px; text-align: center; }
        .sign div { flex: 1; border-top: 1px solid #6b7280; padding-top: 4px; }
        @media print { body { margin: 0; } .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
    </div>
    <div class="head">
        <div class="org">{{ \App\Models\CertificateSetting::current()->organizationName() }}</div>
        <div class="t">Statement of Account</div>
    </div>
    <div class="meta">
        <div><b>Vendor:</b> {{ $vendor->name }}</div>
        <div><b>PAN/VAT:</b> {{ $vendor->pan_vat_no ?? '—' }}</div>
        <div><b>Period:</b> {{ $from?->format('M j, Y') ?? 'Start' }} – {{ $until?->format('M j, Y') ?? 'Today' }}</div>
        <div><b>Printed:</b> {{ now(config('app.business_timezone'))->format('M j, Y') }} ({{ \App\Models\MusterRoll::bsDate(now(config('app.business_timezone'))) }})</div>
    </div>

    @include('costs.vendor-statement', ['vendor' => $vendor, 'statement' => $statement])

    <div class="confirm">
        <b>Balance confirmation.</b> We confirm that the balance payable to us as of ____________ is
        Rs {{ number_format($statement['closing'], 2) }} &nbsp;☐ agreed &nbsp;☐ not agreed (our balance: Rs ____________).
    </div>

    <div class="sign">
        <div>For {{ \App\Models\CertificateSetting::current()->organizationName() }}</div>
        <div>Vendor's signature &amp; stamp</div>
    </div>
</body>
</html>
