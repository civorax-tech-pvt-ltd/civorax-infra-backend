@php
    $pings = $getState() ?? collect();
    $timezone = config('app.business_timezone');
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($pings->isEmpty())
        <p style="font-size: 0.875rem; opacity: 0.7;">No GPS readings for this day.</p>
    @else
        <div style="display: grid; gap: 0.25rem; font-size: 0.875rem;">
            @foreach ($pings as $ping)
                <div style="display: grid; grid-template-columns: 5rem minmax(0, 1fr) auto auto; gap: 0.75rem; align-items: center; padding-block: 0.25rem; border-bottom: 1px solid rgba(var(--gray-400), 0.2);">
                    <span style="font-variant-numeric: tabular-nums;">{{ $ping->recorded_at->timezone($timezone)->format('g:i A') }}</span>
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        @if ($ping->matched)
                            <span style="color: rgb(var(--success-600));">●</span> {{ $ping->placeName() }} ({{ $ping->distance }} m)
                        @else
                            <span style="color: rgb(var(--gray-400));">●</span> Outside all sites
                        @endif
                    </span>
                    <span style="opacity: 0.7;">±{{ $ping->accuracy }} m</span>
                    <a href="{{ $ping->mapUrl() }}" target="_blank" rel="noopener" style="color: rgb(var(--primary-600)); text-decoration: underline;">Map ↗</a>
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
