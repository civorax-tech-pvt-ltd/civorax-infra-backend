<x-filament-widgets::widget>
    <div class="cx-banner">
        <div style="position: relative; z-index: 1;">
            <div style="font-size: 0.78rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; opacity: 0.75;">{{ $date }}</div>
            <h2 style="margin-top: 0.35rem;">{{ $greeting }} 👋</h2>
            @if ($subtitle)
                <p style="margin-top: 0.25rem;">{{ $subtitle }}</p>
            @endif
        </div>

        @if ($actions)
            <div class="cx-banner-actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}">
                        {{ $action['label'] }}
                        @if (($action['badge'] ?? 0) > 0)
                            <span class="cx-badge">{{ $action['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
