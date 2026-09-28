@php
    /** @var \App\Models\MusterRoll $roll */
    $dayDates = $roll->dayDates();
    $lines = $roll->lines()->with('labourer')->get()->sortBy(fn ($line) => $line->work_type.$line->labourer?->name)->values();
    $arrears = $roll->arrears();
    $works = $roll->works()->with('labourer')->get();
    $total = $lines->sum(fn ($line) => (float) $line->total_wage);
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
@endphp

<div class="mr-sheet">
    <div class="mr-head">
        <div class="mr-org">{{ config('app.name') }}</div>
        <div class="mr-title">Muster Roll</div>
        <div class="mr-meta">
            <span><b>Site:</b> {{ $roll->project->title }}@if ($roll->project->site_address), {{ $roll->project->site_address }}@endif</span>
            <span><b>Month:</b> {{ $roll->label() }}</span>
            <span><b>Period:</b> {{ $roll->starts_on->format('M j') }} – {{ $roll->ends_on->format('M j, Y') }}
                @if ($roll->calendar === 'bs') (A.D.) @else (B.S. {{ \App\Models\MusterRoll::bsDate($roll->starts_on) }} – {{ \App\Models\MusterRoll::bsDate($roll->ends_on) }}) @endif
            </span>
        </div>
    </div>

    <div class="mr-part">Part I: Nominal Roll (Attendance &amp; Wages)</div>
    <div class="mr-scroll">
        <table class="mr-table mr-grid">
            <thead>
                <tr>
                    <th>S.N.</th>
                    <th class="mr-left">Name of Laborer</th>
                    <th class="mr-left">Father's Name</th>
                    <th class="mr-left">Work Type</th>
                    @foreach ($dayDates as $day => $date)
                        <th class="mr-day {{ $date->isSaturday() ? 'mr-holiday' : '' }}" title="{{ $date->format('D, M j') }}">{{ $day }}</th>
                    @endforeach
                    <th>Total Days</th>
                    <th>Wage Rate (Rs)</th>
                    <th>Total Wage (Rs)</th>
                    <th class="mr-sign">Signature</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="mr-left mr-nowrap">{{ $line->labourer?->name }}</td>
                        <td class="mr-left mr-nowrap">{{ $line->labourer?->father_name }}</td>
                        <td class="mr-left mr-nowrap">{{ \App\Models\Labourer::WORK_TYPES[$line->work_type] ?? $line->work_type }}</td>
                        @foreach ($dayDates as $day => $date)
                            @php $code = $line->days[$day] ?? $line->days[(string) $day] ?? ''; @endphp
                            <td class="mr-day {{ $date->isSaturday() ? 'mr-holiday' : '' }} mr-code-{{ substr($code, 0, 1) }}">{{ $code }}</td>
                        @endforeach
                        <td>
                            {{ $fmt($line->present_days) }}
                            @if ((float) $line->overtime_hours > 0)<div class="mr-small">+{{ $fmt($line->overtime_hours) }} h OT</div>@endif
                        </td>
                        <td class="mr-right">{{ number_format((float) $line->wage_rate, 2) }}</td>
                        <td class="mr-right">{{ number_format((float) $line->total_wage, 2) }}</td>
                        <td class="mr-sign"></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($dayDates) + 8 }}" class="mr-empty">No labour attendance marked in this period yet.</td></tr>
                @endforelse
            </tbody>
            @if ($lines->isNotEmpty())
                <tfoot>
                    <tr>
                        <td colspan="{{ count($dayDates) + 4 }}" class="mr-right"><b>Total</b></td>
                        <td><b>{{ $fmt($lines->sum(fn ($line) => (float) $line->present_days)) }}</b></td>
                        <td></td>
                        <td class="mr-right"><b>{{ number_format($total, 2) }}</b></td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div class="mr-note">P = present · H = half day · A = absent · +n = overtime hours (paid at {{ config('site.hours_per_day') }} hours per day's wage). Shaded columns are Saturdays.</div>

    <div class="mr-part">Part II: Arrears of Wages (Unpaid Wages)</div>
    <div class="mr-scroll">
        <table class="mr-table">
            <thead>
                <tr>
                    <th>S.N.</th>
                    <th class="mr-left">Name of Worker</th>
                    <th class="mr-left">Month</th>
                    <th>Amount Due (Rs)</th>
                    <th>Amount Paid (Rs)</th>
                    <th>Amount Unpaid (Rs)</th>
                    <th class="mr-left">Reason</th>
                    <th class="mr-sign">Signature</th>
                    <th class="mr-left">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($arrears as $row)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="mr-left mr-nowrap">{{ $row['line']->labourer?->name }}</td>
                        <td class="mr-left mr-nowrap">{{ $row['month'] }}</td>
                        <td class="mr-right">{{ number_format($row['due'], 2) }}</td>
                        <td class="mr-right">{{ number_format($row['paid'], 2) }}</td>
                        <td class="mr-right"><b>{{ number_format($row['unpaid'], 2) }}</b></td>
                        <td class="mr-left">{{ $row['line']->arrear_reason }}</td>
                        <td class="mr-sign"></td>
                        <td class="mr-left">{{ $row['line']->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="mr-empty">No unpaid wages.</td></tr>
                @endforelse
            </tbody>
            @if (count($arrears))
                <tfoot>
                    <tr>
                        <td colspan="5" class="mr-right"><b>Total unpaid</b></td>
                        <td class="mr-right"><b>{{ number_format(collect($arrears)->sum('unpaid'), 2) }}</b></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="mr-part">Part III: Work Performed (Measurement Record)</div>
    <div class="mr-scroll">
        <table class="mr-table">
            <thead>
                <tr>
                    <th>S.N.</th>
                    <th class="mr-left">Name of Worker</th>
                    <th class="mr-left">Work Description</th>
                    <th>Quantity</th>
                    <th>MB Ref (Pg)</th>
                    <th class="mr-left">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($works as $work)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="mr-left mr-nowrap">{{ $work->labourer?->name ?? 'All / gang' }}</td>
                        <td class="mr-left">{{ $work->description }}</td>
                        <td class="mr-right mr-nowrap">{{ $work->quantity !== null ? $fmt($work->quantity) : '' }} {{ $work->unit }}</td>
                        <td>{{ $work->mb_ref }}</td>
                        <td class="mr-left">{{ $work->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="mr-empty">No work recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mr-signatures">
        <div><div class="mr-line"></div>Prepared by<br><span>{{ $roll->preparer?->name }}</span></div>
        <div><div class="mr-line"></div>Checked by (Site Engineer)</div>
        <div><div class="mr-line"></div>Approved by<br><span>{{ $roll->approver?->name }}@if ($roll->approved_at) · {{ $roll->approved_at->timezone(config('app.business_timezone'))->format('M j, Y') }}@endif</span></div>
    </div>
</div>

<style>
    .mr-sheet { font-size: 12px; color: #111827; }
    .dark .mr-sheet { color: #e5e7eb; }
    .mr-head { text-align: center; margin-bottom: 10px; }
    .mr-org { font-size: 16px; font-weight: 700; }
    .mr-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; margin: 2px 0 6px; }
    .mr-meta { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 18px; }
    .mr-part { font-weight: 700; margin: 16px 0 6px; font-size: 13px; }
    .mr-scroll { overflow-x: auto; }
    .mr-table { border-collapse: collapse; width: 100%; }
    .mr-table th, .mr-table td { border: 1px solid #9ca3af; padding: 3px 5px; text-align: center; vertical-align: middle; }
    .dark .mr-table th, .dark .mr-table td { border-color: #4b5563; }
    .mr-table th { background: #f3f4f6; font-weight: 600; }
    .dark .mr-table th { background: rgb(255 255 255 / .06); }
    .mr-grid .mr-day { min-width: 22px; padding: 3px 1px; font-size: 11px; }
    .mr-holiday { background: rgb(243 244 246 / .9); }
    .dark .mr-holiday { background: rgb(255 255 255 / .04); }
    .mr-code-A { color: #dc2626; }
    .mr-code-H { color: #d97706; font-weight: 600; }
    .mr-left { text-align: left !important; }
    .mr-right { text-align: right !important; }
    .mr-nowrap { white-space: nowrap; }
    .mr-sign { min-width: 70px; }
    .mr-small { font-size: 10px; color: #6b7280; }
    .mr-empty { color: #6b7280; padding: 12px !important; }
    .mr-note { font-size: 11px; color: #6b7280; margin-top: 4px; }
    .mr-signatures { display: flex; justify-content: space-between; gap: 24px; margin-top: 44px; text-align: center; }
    .mr-signatures > div { flex: 1; }
    .mr-signatures span { font-size: 11px; color: #6b7280; }
    .mr-line { border-top: 1px solid #6b7280; margin-bottom: 4px; }
</style>
