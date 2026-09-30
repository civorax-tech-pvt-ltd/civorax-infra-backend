<?php

namespace App\Models;

/**
 * Cost per BOQ item (brief §8). An item's actual cost is always calculated, never typed:
 * recorded = tagged ledger entries + approved material issues; estimated = norm × executed quantity × average rate
 * for materials with a norm but no issue on the item. Untagged costs stay only in the project ledger.
 */
class BoqItemCostReport
{
    /**
     * @var array<int, ?float>
     */
    protected array $rates = [];

    public function __construct(public readonly Project $project) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $materials = KeyMaterial::query()->pluck('name', 'id');
        $materialReport = $this->project->materialReport();

        return $this->project->boqItems()
            ->with(['latestApprovedMeasurement', 'costs' => fn ($query) => $query->approved(), 'materialIssues' => fn ($query) => $query->approved()])
            ->get()
            ->map(function (BoqItem $item) use ($materials, $materialReport): array {
                $direct = round((float) $item->costs->sum('amount'), 2);
                $issued = round((float) $item->materialIssues->sum('value'), 2);
                $issuedMaterials = $item->materialIssues->pluck('key_material_id')->unique()->all();

                $estimated = 0.0;
                $estimates = [];

                foreach ((array) $item->norms as $norm) {
                    $materialId = (int) ($norm['key_material_id'] ?? 0);

                    if (! $materialId || in_array($materialId, $issuedMaterials, true) || empty($norm['per_unit'])) {
                        continue;
                    }

                    $rate = $this->rates[$materialId] ??= $materialReport->averageRate($materialId);
                    $quantity = (float) $norm['per_unit'] * $item->executedQuantity();

                    if ($rate !== null && $quantity > 0) {
                        $estimated += $quantity * $rate;
                        $estimates[] = ($materials[$materialId] ?? 'Material').' '.rtrim(rtrim(number_format($quantity, 2), '0'), '.');
                    }
                }

                $estimated = round($estimated, 2);
                $recorded = round($direct + $issued, 2);
                $actual = round($recorded + $estimated, 2);
                $earned = $item->earnedValue();
                $progress = min(100, $item->progressPercent());

                return [
                    'item' => $item,
                    'planned' => (float) $item->planned_value,
                    'progress' => $item->progressPercent(),
                    'earned' => $earned,
                    'direct' => $direct,
                    'issued' => $issued,
                    'estimated' => $estimated,
                    'estimates' => $estimates,
                    'actual' => $actual,
                    'variance' => round($earned - $actual, 2),
                    'projected' => $progress > 0 ? round($actual / ($progress / 100), 2) : null,
                    'coverage' => $actual > 0 ? round($recorded / $actual * 100) : null,
                    'status' => match (true) {
                        $actual <= 0 && $earned <= 0 => 'none',
                        $actual <= $earned => 'green',
                        $actual <= $earned * 1.1 => 'amber',
                        default => 'red',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Project costs not tagged to any item (they still count in project profit).
     */
    public function untaggedCost(): float
    {
        return round((float) $this->project->costs()->approved()->whereNull('boq_item_id')->sum('amount'), 2);
    }
}
