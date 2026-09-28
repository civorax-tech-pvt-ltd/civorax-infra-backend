<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section>
        @include('site.labourer-ledger', ['labourer' => $this->getLabourer(), 'ledger' => $this->ledger(), 'filtered' => filled($filters['project_id'] ?? null)])
    </x-filament::section>
</x-filament-panels::page>
