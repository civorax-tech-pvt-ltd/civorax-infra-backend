<x-filament-panels::page>
    <x-filament::section>
        @include('costs.report', ['report' => $this->getProject()->costReport(), 'showLedger' => true])
    </x-filament::section>
</x-filament-panels::page>
