<x-filament-widgets::widget>
    <x-filament::section heading="Behind schedule" description="Active projects whose progress is below the share of time already used.">
        @php($rows = $this->getProjects())

        @if ($rows->isEmpty())
            <p style="font-size: 0.875rem; opacity: 0.7;">Every active project is on schedule.</p>
        @else
            <div style="display: grid; gap: 0.75rem;">
                @foreach ($rows as $row)
                    @php($project = $row['project'])
                    <a href="{{ $row['url'] }}" style="display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr) auto; gap: 1rem; align-items: center;">
                        <div style="min-width: 0;">
                            <div style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $project->title }}</div>
                            <div style="font-size: 0.75rem; opacity: 0.7;">{{ $project->client?->contact_person }} · {{ \App\Models\Project::STATUSES[$project->status] ?? $project->status }}</div>
                        </div>
                        <div>
                            @include('filament.components.progress-bar', ['percent' => $project->progress])
                            <div style="font-size: 0.75rem; opacity: 0.7;">Expected by now: {{ $row['expected'] }}%</div>
                        </div>
                        <div style="font-size: 0.75rem; text-align: right; white-space: nowrap; color: {{ $project->estimated_end_date->isPast() ? 'rgb(var(--danger-600))' : 'inherit' }};">
                            {{ $project->estimated_end_date->isPast() ? 'Overdue since' : 'Due' }} {{ $project->estimated_end_date->format('M j, Y') }}
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
