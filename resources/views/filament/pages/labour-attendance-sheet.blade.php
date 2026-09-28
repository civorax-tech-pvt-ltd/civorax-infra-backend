<x-filament-panels::page>
    @php
        $locked = $this->lockedBy();
        $labourers = $this->labourers();
        $summary = $this->summary();
        $statuses = ['present' => ['P', 'Present', 'success'], 'half_day' => ['H', 'Half', 'warning'], 'absent' => ['A', 'Absent', 'danger']];
    @endphp

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($locked)
        <div class="cx-site-banner cx-site-banner--warning">
            <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5" />
            <span>This day is locked: the <strong>{{ $locked->label() }}</strong> muster roll is {{ $locked->statusLabel() }}. An approver must return it before attendance can change.</span>
        </div>
    @endif

    @if ($this->project())
        <div class="cx-site-stats">
            <div><span>{{ $summary['headcount'] }}</span> on site</div>
            <div><span>{{ rtrim(rtrim(number_format($summary['present'], 1), '0'), '.') }}</span> man-days</div>
            <div><span>Rs {{ number_format($summary['wages']) }}</span> wages today</div>
        </div>

        <x-filament::section :heading="$labourers->isEmpty() ? null : 'Labourers ('.$labourers->count().')'">
            @if ($labourers->isEmpty())
                <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No labourers on this site yet. Use <strong>Add labourer</strong> or <strong>New labourer</strong> above.
                </div>
            @else
                <x-slot name="headerEnd">
                    @unless ($locked)
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-check-badge" wire:click="markAllPresent">
                            Mark rest present
                        </x-filament::button>
                    @endunless
                </x-slot>

                <div class="cx-labour-list">
                    @foreach ($labourers as $labourer)
                        @php $row = $rows[$labourer->id] ?? ['status' => null, 'overtime_hours' => 0]; @endphp
                        <div class="cx-labour-row" wire:key="labourer-{{ $labourer->id }}">
                            <div class="cx-labour-name">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $labourer->name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $labourer->workTypeLabel() }} · Rs {{ number_format((float) $labourer->daily_wage) }}/day
                                    @if ($labourer->father_name) · s/o {{ $labourer->father_name }} @endif
                                    @if ($labourer->contractor) · {{ $labourer->contractor->name }} @endif
                                </div>
                            </div>

                            <div class="cx-labour-controls">
                                <div class="cx-pills" role="radiogroup" aria-label="Attendance for {{ $labourer->name }}">
                                    @foreach ($statuses as $value => [$code, $label, $color])
                                        <button type="button"
                                            role="radio"
                                            aria-checked="{{ $row['status'] === $value ? 'true' : 'false' }}"
                                            title="{{ $label }}"
                                            @disabled($locked)
                                            wire:click="setStatus({{ $labourer->id }}, '{{ $value }}')"
                                            class="cx-pill cx-pill--{{ $color }} {{ $row['status'] === $value ? 'is-on' : '' }}">
                                            {{ $code }}
                                        </button>
                                    @endforeach
                                </div>

                                <label class="cx-ot">
                                    <span>OT h</span>
                                    <input type="number" min="0" max="16" step="0.5"
                                        wire:model.blur="rows.{{ $labourer->id }}.overtime_hours"
                                        @disabled($locked || $row['status'] === 'absent' || $row['status'] === null)>
                                </label>

                                @if ($row['status'] === null && ! $locked)
                                    <button type="button" class="cx-remove" title="Remove from today's list" wire:click="removeRow({{ $labourer->id }})">
                                        <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        @unless ($locked || $labourers->isEmpty())
            <div class="cx-sticky-save">
                <x-filament::button size="lg" icon="heroicon-o-check" wire:click="save" wire:loading.attr="disabled">
                    Save attendance
                </x-filament::button>
                <span class="text-xs text-gray-500 dark:text-gray-400">Tap a letter again to clear it. Unmarked labourers are not saved.</span>
            </div>
        @endunless
    @endif

    <style>
        .cx-site-banner { display: flex; gap: .6rem; align-items: flex-start; padding: .85rem 1rem; border-radius: .75rem; font-size: .875rem; }
        .cx-site-banner--warning { background: rgb(254 243 199); color: rgb(146 64 14); }
        .dark .cx-site-banner--warning { background: rgb(120 53 15 / .35); color: rgb(253 230 138); }
        .cx-site-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        .cx-site-stats > div { padding: .8rem 1rem; border-radius: .75rem; background: white; box-shadow: 0 1px 2px rgb(0 0 0 / .06); font-size: .8rem; color: rgb(107 114 128); border: 1px solid rgb(0 0 0 / .05); }
        .dark .cx-site-stats > div { background: rgb(255 255 255 / .04); border-color: rgb(255 255 255 / .08); color: rgb(156 163 175); }
        .cx-site-stats span { display: block; font-size: 1.25rem; font-weight: 700; color: rgb(var(--primary-600)); }
        .cx-labour-list { display: flex; flex-direction: column; }
        .cx-labour-row { display: flex; flex-wrap: wrap; gap: .6rem 1rem; align-items: center; justify-content: space-between; padding: .7rem 0; border-bottom: 1px solid rgb(0 0 0 / .06); }
        .dark .cx-labour-row { border-color: rgb(255 255 255 / .08); }
        .cx-labour-row:last-child { border-bottom: 0; }
        .cx-labour-name { min-width: 12rem; flex: 1 1 14rem; }
        .cx-labour-controls { display: flex; align-items: center; gap: .75rem; }
        .cx-pills { display: inline-flex; gap: .35rem; }
        .cx-pill { width: 2.6rem; height: 2.6rem; border-radius: .6rem; font-weight: 700; border: 1.5px solid rgb(209 213 219); color: rgb(107 114 128); background: white; transition: all .12s; }
        .dark .cx-pill { background: rgb(255 255 255 / .04); border-color: rgb(255 255 255 / .15); color: rgb(156 163 175); }
        .cx-pill:disabled { opacity: .55; cursor: not-allowed; }
        .cx-pill--success.is-on { background: rgb(22 163 74); border-color: rgb(22 163 74); color: white; }
        .cx-pill--warning.is-on { background: rgb(217 119 6); border-color: rgb(217 119 6); color: white; }
        .cx-pill--danger.is-on { background: rgb(220 38 38); border-color: rgb(220 38 38); color: white; }
        .cx-ot { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; color: rgb(107 114 128); }
        .cx-ot input { width: 4.2rem; padding: .45rem .5rem; border-radius: .5rem; border: 1px solid rgb(209 213 219); background: transparent; font-size: .875rem; }
        .dark .cx-ot input { border-color: rgb(255 255 255 / .15); color: white; }
        .cx-ot input:disabled { opacity: .45; }
        .cx-remove { color: rgb(156 163 175); padding: .25rem; }
        .cx-sticky-save { position: sticky; bottom: .75rem; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: .75rem 1rem; border-radius: .9rem; background: rgb(255 255 255 / .92); backdrop-filter: blur(6px); box-shadow: 0 6px 24px rgb(0 0 0 / .12); z-index: 10; }
        .dark .cx-sticky-save { background: rgb(17 24 39 / .92); }
        @media (max-width: 480px) { .cx-site-stats span { font-size: 1rem; } .cx-labour-controls { width: 100%; justify-content: space-between; } }
    </style>
</x-filament-panels::page>
