<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Muster Roll – {{ $roll->label() }} – {{ $roll->project->title }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 16px; background: #fff; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 14px; }
        .toolbar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #374151; }
        .status { margin-left: auto; align-self: center; font-size: 12px; color: #6b7280; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none; }
            .mr-scroll { overflow: visible !important; }
            .mr-sheet { font-size: 9px !important; }
            .mr-grid .mr-day { min-width: 0 !important; font-size: 8px !important; }
            .mr-table th, .mr-table td { padding: 2px 2px !important; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
        <span class="status">Status: {{ \App\Models\MusterRoll::STATUSES[$roll->status] ?? $roll->status }}</span>
    </div>

    @include('site.muster-roll-sheet', ['roll' => $roll])
</body>
</html>
