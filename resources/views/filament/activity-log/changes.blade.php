@php
    /** @var list<array{field: string, old: string, new: string}> $changes */
    $showOld = $event !== 'created';
    $showNew = $event !== 'deleted';
@endphp

@if ($changes === [])
    <div style="font-size:.9rem;color:rgb(107 114 128);">No field details were recorded for this entry.</div>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <thead>
                <tr style="text-align:left;color:rgb(107 114 128);border-bottom:1px solid rgb(0 0 0 / .1);">
                    <th style="padding:.4rem .5rem;">Field</th>
                    @if ($showOld)<th style="padding:.4rem .5rem;">Before</th>@endif
                    @if ($showNew)<th style="padding:.4rem .5rem;">{{ $showOld ? 'After' : 'Value' }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr style="border-bottom:1px solid rgb(0 0 0 / .05);vertical-align:top;">
                        <td style="padding:.4rem .5rem;font-weight:600;white-space:nowrap;">{{ $change['field'] }}</td>
                        @if ($showOld)<td style="padding:.4rem .5rem;color:#b91c1c;word-break:break-word;">{{ $change['old'] }}</td>@endif
                        @if ($showNew)<td style="padding:.4rem .5rem;color:#15803d;word-break:break-word;">{{ $change['new'] }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
