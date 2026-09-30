<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * Key material quantities for one project: planned vs purchased vs received vs used vs expected use.
 */
class ProjectMaterialReport
{
    public function __construct(public readonly Project $project) {}

    /**
     * @return list<array{material: KeyMaterial, planned: ?float, purchased: float, received: float, transferred_in: float, transferred_out: float, left: ?float, counted_on: ?Carbon, used: ?float, expected_use: ?float, flag: ?string}>
     */
    public function rows(): array
    {
        $projectId = $this->project->getKey();
        $plans = ProjectMaterialPlan::query()->where('project_id', $projectId)->pluck('planned_quantity', 'key_material_id');
        $purchased = $this->purchasedQuantities();
        $received = MaterialDelivery::query()->where('project_id', $projectId)->groupBy('key_material_id')->selectRaw('key_material_id, SUM(quantity) as q')->pluck('q', 'key_material_id');
        $in = MaterialTransfer::query()->where('to_project_id', $projectId)->groupBy('key_material_id')->selectRaw('key_material_id, SUM(quantity) as q')->pluck('q', 'key_material_id');
        $out = MaterialTransfer::query()->where('from_project_id', $projectId)->groupBy('key_material_id')->selectRaw('key_material_id, SUM(quantity) as q')->pluck('q', 'key_material_id');
        $counts = MaterialStockCount::query()->where('project_id', $projectId)->orderBy('counted_on')->orderBy('id')->get()->keyBy('key_material_id'); // last one wins
        $work = $this->project->costReport()->workComplete()['percent'];

        $ids = collect([$plans->keys(), array_keys($purchased), $received->keys(), $in->keys(), $out->keys(), $counts->keys()])->flatten()->unique();

        return KeyMaterial::query()->whereKey($ids)->orderBy('sort')->get()->map(function (KeyMaterial $material) use ($plans, $purchased, $received, $in, $out, $counts, $work): array {
            $id = $material->id;
            $planned = isset($plans[$id]) ? (float) $plans[$id] : null;
            $count = $counts[$id] ?? null;
            $available = (float) ($received[$id] ?? 0) + (float) ($in[$id] ?? 0) - (float) ($out[$id] ?? 0);
            $used = $count ? round($available - (float) $count->quantity_left, 2) : null;
            $expected = $planned !== null ? round($planned * min(100, $work) / 100, 2) : null;
            $bought = $purchased[$id] ?? 0.0;

            return [
                'material' => $material,
                'planned' => $planned,
                'purchased' => $bought,
                'received' => (float) ($received[$id] ?? 0),
                'transferred_in' => (float) ($in[$id] ?? 0),
                'transferred_out' => (float) ($out[$id] ?? 0),
                'left' => $count ? (float) $count->quantity_left : null,
                'counted_on' => $count?->counted_on,
                'used' => $used,
                'expected_use' => $expected,
                'flag' => match (true) {
                    $planned !== null && $bought > $planned * 1.05 => "Purchased {$this->qty($bought)} {$material->unit}, planned {$this->qty($planned)}, work {$work}% done",
                    $used !== null && $expected !== null && $expected > 0 && $used > $expected * 1.1 => "Used {$this->qty($used)} {$material->unit}, expected about {$this->qty($expected)} at {$work}% done",
                    default => null,
                },
            ];
        })->values()->all();
    }

    /**
     * Quantities on approved purchase bill lines that name a key material.
     *
     * @return array<int, float>
     */
    protected function purchasedQuantities(): array
    {
        $totals = [];

        PurchaseBill::query()->where('project_id', $this->project->getKey())->approved()->whereNotNull('items')->pluck('items')->each(function ($items) use (&$totals): void {
            foreach ((array) $items as $line) {
                if (! empty($line['key_material_id'])) {
                    $totals[(int) $line['key_material_id']] = ($totals[(int) $line['key_material_id']] ?? 0) + (float) ($line['quantity'] ?? 0);
                }
            }
        });

        return $totals;
    }

    /**
     * Average purchase rate per unit on this project's approved bills (for valuing transfers), VAT-inclusive
     * when not claimable; null when bill lines have no rates.
     */
    public function averageRate(int $materialId): ?float
    {
        $quantity = 0.0;
        $value = 0.0;

        PurchaseBill::query()->where('project_id', $this->project->getKey())->approved()->whereNotNull('items')->get()->each(function (PurchaseBill $bill) use ($materialId, &$quantity, &$value): void {
            $factor = (float) $bill->base_amount > 0 ? $bill->ledgerCost() / (float) $bill->base_amount : 1.0;

            foreach ((array) $bill->items as $line) {
                if ((int) ($line['key_material_id'] ?? 0) === $materialId && ! empty($line['rate'])) {
                    $quantity += (float) $line['quantity'];
                    $value += (float) $line['quantity'] * (float) $line['rate'] * $factor;
                }
            }
        });

        return $quantity > 0 ? round($value / $quantity, 2) : null;
    }

    protected function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
