<?php

namespace App\Filament\Resources\PurchaseBillResource\Pages;

use App\Filament\Resources\PurchaseBillResource;
use App\Models\WorkOrder;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseBill extends CreateRecord
{
    protected static string $resource = PurchaseBillResource::class;

    protected static ?string $title = 'Enter purchase bill';

    /**
     * Opened from a work order's "Enter bill": start with that order filled in.
     */
    protected function afterFill(): void
    {
        $order = WorkOrder::query()->open()->find(request()->integer('work_order'));

        if ($order !== null) {
            $this->form->fill([
                ...$this->form->getRawState(),
                'work_order_id' => $order->id,
                'project_id' => $order->project_id,
                'vendor_id' => $order->vendor_id,
                'vendor_pan_vat' => $order->vendor?->pan_vat_no,
                'category' => 'subcontract',
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => 'pending', 'entered_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
