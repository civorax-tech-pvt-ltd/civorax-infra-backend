@php
    /** @var \App\Models\BoqItem $item */
    $colors = ['approved' => '#15803d', 'pending' => '#b45309', 'rejected' => '#b91c1c'];
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3), '0'), '.');
@endphp

<div style="display:flex;flex-direction:column;gap:.75rem;">
    <div style="font-size:.85rem;color:rgb(107 114 128);">
        BOQ quantity {{ $qty($item->quantity) }} {{ $item->unit }} · approved to date {{ $qty($item->executedQuantity()) }} {{ $item->unit }} ({{ $item->progressPercent() }}%)
        @if ($item->isExcess()) · <b style="color:#b91c1c">Excess, variation needed</b> @endif
    </div>

    @forelse ($item->measurements as $m)
        <div style="border:1px solid rgb(0 0 0 / .08);border-radius:.75rem;padding:.75rem .9rem;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem;align-items:center;">
                <div>
                    <b>{{ $qty($m->executed_quantity) }} {{ $item->unit }}</b> done to date
                    <span style="color:rgb(107 114 128);font-size:.8rem;">· {{ $m->measured_date->format('M j, Y') }} ({{ \App\Models\MusterRoll::bsDate($m->measured_date) }}) · by {{ $m->enteredBy?->name ?? '—' }}</span>
                </div>
                <span style="font-size:.75rem;font-weight:700;color:#fff;background:{{ $colors[$m->status] ?? '#6b7280' }};padding:.15rem .55rem;border-radius:999px;">
                    {{ \App\Models\BoqMeasurement::STATUSES[$m->status] ?? $m->status }}
                </span>
            </div>
            @if ($m->remarks)<div style="font-size:.85rem;margin-top:.35rem;">{{ $m->remarks }}</div>@endif
            @if ($m->approver)
                <div style="font-size:.78rem;color:rgb(107 114 128);margin-top:.3rem;">
                    {{ $m->status === 'approved' ? 'Approved' : 'Reviewed' }} by {{ $m->approver->name }} {{ $m->approved_at?->diffForHumans() }}@if ($m->review_note) · “{{ $m->review_note }}”@endif
                </div>
            @endif
            @if ($m->photos)
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:.4rem;margin-top:.5rem;">
                    @foreach ($m->photoUrls() as $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener" style="aspect-ratio:1;border-radius:.5rem;overflow:hidden;display:block;background:rgb(0 0 0 / .05);">
                            <img src="{{ $url }}" alt="Measurement photo" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div style="color:rgb(107 114 128);font-size:.9rem;">No measurements yet. Use <b>Measure</b> to record work done.</div>
    @endforelse
</div>
