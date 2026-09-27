<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if ($cards->isEmpty())
            <p style="font-size: 0.875rem; opacity: 0.7;">{{ $empty }}</p>
        @else
            <div class="cx-cards">
                @foreach ($cards as $card)
                    <a href="{{ $card['url'] }}" class="cx-card">
                        <div style="display: flex; justify-content: space-between; gap: 0.5rem; align-items: start;">
                            <div class="cx-card-title">{{ $card['title'] }}</div>
                            <span class="cx-pill" style="flex: none;">{{ $card['status'] }}</span>
                        </div>
                        @if (filled($card['meta']))
                            <div class="cx-card-meta" style="margin-top: 0.2rem;">{{ $card['meta'] }}</div>
                        @endif
                        <div style="margin-top: 0.9rem;">
                            @include('filament.components.progress-bar', ['percent' => $card['progress']])
                        </div>
                        @foreach (array_filter([$card['footer'] ?? null, $card['extra'] ?? null]) as $line)
                            <div class="cx-card-meta" style="margin-top: 0.5rem;">{{ $line }}</div>
                        @endforeach
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
