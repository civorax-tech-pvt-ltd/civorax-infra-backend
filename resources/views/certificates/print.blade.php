<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $certificate->number }} – {{ $certificate->student_name }}</title>
    <style>
        @page { size: A4 landscape; margin: 0; }
        html, body { margin: 0; background: #e5e7eb; }
        .toolbar { display: flex; gap: 8px; justify-content: center; padding: 14px; font-family: Arial, sans-serif; }
        .toolbar button { padding: 9px 16px; border-radius: 8px; border: 1px solid #d1d5db; background: #1b2466; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #374151; }
        .toolbar span { align-self: center; font-size: 12px; color: #4b5563; }
        .sheet { padding: 0 12px 24px; overflow-x: auto; }
        .sheet .cert { box-shadow: 0 10px 30px rgba(0, 0, 0, .15); }
        @media print {
            html, body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; overflow: visible; }
            .sheet .cert { box-shadow: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Download PDF / Print</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
        <span>Choose "Save as PDF", A4 landscape, margins "None".</span>
    </div>
    <div class="sheet">
        @include('certificates.certificate')
    </div>
</body>
</html>
