<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($project = $this->project())
        <x-filament::section heading="Key materials">
            @include('costs.materials', ['rows' => $project->materialReport()->rows(), 'work' => $project->costReport()->workComplete()])
        </x-filament::section>

        @php
            $deliveries = \App\Models\MaterialDelivery::with(['material', 'vendor', 'enteredBy'])->where('project_id', $project->id)->latest('delivered_on')->latest('id')->limit(15)->get();
            $transfers = \App\Models\MaterialTransfer::with(['material', 'toProject', 'fromProject'])->where(fn ($q) => $q->where('from_project_id', $project->id)->orWhere('to_project_id', $project->id))->latest('transferred_on')->limit(10)->get();
        @endphp

        <x-filament::section heading="Recent deliveries" collapsible>
            <div class="cr">
                <table class="cr-table">
                    <thead><tr><th>Date</th><th>Material</th><th>Supplier / challan</th><th>By</th><th>Quantity</th></tr></thead>
                    <tbody>
                        @forelse ($deliveries as $d)
                            <tr>
                                <td class="nw">{{ $d->delivered_on->format('M j, Y') }}</td>
                                <td>{{ $d->material?->name }}</td>
                                <td>{{ $d->vendor?->name }}@if ($d->challan_no) · challan {{ $d->challan_no }}@endif
                                    @foreach ((array) $d->photos as $photo) · <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo) }}" target="_blank" rel="noopener">photo</a>@endforeach
                                </td>
                                <td>{{ $d->enteredBy?->name }}</td>
                                <td>{{ rtrim(rtrim(number_format((float) $d->quantity, 2), '0'), '.') }} {{ $d->material?->unit }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="cr-muted">No deliveries recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($project->track_item_costs)
            @php $issues = \App\Models\MaterialIssue::with(['material', 'boqItem', 'issuer'])->where('project_id', $project->id)->latest('issued_on')->latest('id')->limit(20)->get(); @endphp
            <x-filament::section heading="Material issued to BOQ items" collapsible>
                <div class="cr">
                    <table class="cr-table">
                        <thead><tr><th>Date</th><th>BOQ item</th><th>Material</th><th>By</th><th>Status</th><th>Value</th></tr></thead>
                        <tbody>
                            @forelse ($issues as $issue)
                                <tr>
                                    <td class="nw">{{ $issue->issued_on->format('M j, Y') }}</td>
                                    <td>{{ $issue->boqItem?->label() }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $issue->quantity, 2), '0'), '.') }} {{ $issue->material?->unit }} {{ $issue->material?->name }}</td>
                                    <td>{{ $issue->issuer?->name }}</td>
                                    <td>
                                        @if ($issue->status === 'approved')
                                            Approved
                                        @elseif (\App\Models\MaterialIssue::canBeReviewedBy(auth()->user(), $issue))
                                            <x-filament::button size="xs" color="success" wire:click="approveIssue({{ $issue->id }})">Approve</x-filament::button>
                                        @else
                                            Pending
                                        @endif
                                    </td>
                                    <td>Rs {{ number_format((float) $issue->value, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="cr-muted">Nothing issued yet. Use "Issue to BOQ item".</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($transfers->isNotEmpty())
            <x-filament::section heading="Transfers and returns" collapsible>
                <div class="cr">
                    <table class="cr-table">
                        <thead><tr><th>Date</th><th>Material</th><th>Movement</th><th>Quantity</th><th>Value</th></tr></thead>
                        <tbody>
                            @foreach ($transfers as $t)
                                <tr>
                                    <td class="nw">{{ $t->transferred_on->format('M j, Y') }}</td>
                                    <td>{{ $t->material?->name }}</td>
                                    <td>
                                        @if ($t->from_project_id === $project->id)
                                            {{ $t->isReturn() ? 'Returned to supplier' : 'To '.$t->toProject?->title }}
                                        @else
                                            From {{ $t->fromProject?->title }}
                                        @endif
                                    </td>
                                    <td>{{ rtrim(rtrim(number_format((float) $t->quantity, 2), '0'), '.') }} {{ $t->material?->unit }}</td>
                                    <td>Rs {{ number_format((float) $t->value, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
        @include('costs.report-styles')
    @else
        <x-filament::section>Choose a site.</x-filament::section>
    @endif
</x-filament-panels::page>
