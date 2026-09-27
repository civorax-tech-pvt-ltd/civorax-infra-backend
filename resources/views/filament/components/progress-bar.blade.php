@php
    $percent = max(0, min(100, (int) ($percent ?? (isset($getState) ? $getState() : 0))));
    $color = $percent >= 100 ? 'var(--success-500)' : 'var(--primary-500)';
@endphp

<div style="display: flex; align-items: center; gap: 0.5rem; min-width: 8rem;">
    <div style="flex: 1; height: 0.5rem; border-radius: 9999px; background: rgba(var(--gray-400), 0.25); overflow: hidden;">
        <div style="height: 100%; width: {{ $percent }}%; border-radius: 9999px; background: rgb({{ $color }});"></div>
    </div>
    <span style="font-size: 0.75rem; font-variant-numeric: tabular-nums; min-width: 2.5rem; text-align: right;">{{ $percent }}%</span>
</div>
