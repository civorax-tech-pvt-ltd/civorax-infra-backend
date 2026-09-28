<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 16px; color: #111827; background: #fff; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 14px; }
        .toolbar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #374151; }
        .head { text-align: center; margin-bottom: 14px; }
        .head .org { font-size: 17px; font-weight: 700; }
        .head .t { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
        .meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 20px; font-size: 13px; margin-bottom: 12px; }
        .sign { display: flex; justify-content: space-between; gap: 30px; margin-top: 50px; font-size: 12px; text-align: center; }
        .sign div { flex: 1; border-top: 1px solid #6b7280; padding-top: 4px; }
        @media print { body { margin: 0; } .toolbar { display: none; } .lg-scroll { overflow: visible !important; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
    </div>

    <div class="head">
        <div class="org">{{ config('app.name') }}</div>
        <div class="t">Naike Account</div>
    </div>

    <div class="meta">
        <div><b>Naike:</b> {{ $contractor->name }}</div>
        <div><b>Phone:</b> {{ $contractor->phone ?? '—' }}</div>
        <div><b>PAN:</b> {{ $contractor->pan_no ?? '—' }}</div>
        <div><b>Printed:</b> {{ now(config('app.business_timezone'))->format('M j, Y') }} ({{ \App\Models\MusterRoll::bsDate(now(config('app.business_timezone'))) }})</div>
    </div>

    @include('site.naike-account', ['labourers' => $labourers, 'payments' => $payments])

    <div class="sign">
        <div>Naike's signature</div>
        <div>Paid by</div>
        <div>Approved by</div>
    </div>
</body>
</html>
