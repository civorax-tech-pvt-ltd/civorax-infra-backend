<x-filament-panels::page>
    <x-filament::section>
        @include('site.naike-account', [
            'labourers' => $this->getContractor()->labourers()->withBalance()->orderBy('name')->get(),
            'payments' => $this->getContractor()->wagePayments()->with(['labourer', 'project'])->latest('paid_on')->limit(100)->get(),
        ])
        <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:.5rem;">
            To pay the gang, open an approved muster roll and use <b>Pay wages</b> → "Handed to naike". Each labourer's own ledger is updated.
        </p>
    </x-filament::section>
</x-filament-panels::page>
