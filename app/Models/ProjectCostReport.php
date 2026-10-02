<?php

namespace App\Models;

/**
 * The project cost report (brief §5), calculated only from the approved cost ledger, budgets, payments
 * and BOQ progress, never from typed totals.
 */
class ProjectCostReport
{
    public const WARNING_PERCENT = 80;

    /**
     * @var array<string, float>
     */
    protected array $budgets;

    /**
     * @var array<string, float>
     */
    protected array $actuals;

    public function __construct(public readonly Project $project)
    {
        $this->budgets = $project->budgets()->pluck('amount', 'category')->map(fn ($amount): float => (float) $amount)->all();

        // Approved extra work brings its own expected cost into that category's budget.
        foreach ($project->variations()->approved()->where('cost_budget', '>', 0)->get() as $variation) {
            $category = $variation->budget_category ?: 'contingency';
            $this->budgets[$category] = ($this->budgets[$category] ?? 0) + (float) $variation->cost_budget;
        }
        $this->actuals = $project->costs()->approved()
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }

    public function contractValue(): float
    {
        return $this->project->contractValue();
    }

    public function variationsTotal(): float
    {
        return round($this->contractValue() - (float) $this->project->fee, 2);
    }

    /**
     * Engineers' and supervisors' salaries charged to this project by attendance days (kept out of the
     * category budget so "Project profit" stays comparable; shown as its own line).
     */
    public function staffCost(): float
    {
        return round((float) $this->project->staffCostAllocations()->sum('amount'), 2);
    }

    public function profitAfterStaffCost(): float
    {
        return round($this->projectedProfit() - $this->staffCost(), 2);
    }

    /**
     * The same project seen as if the company were VAT registered (for comparing before registering):
     * a VAT-inclusive price then carries VAT that is not revenue, and supplier VAT on VAT bills becomes claimable.
     *
     * @return array{revenue: float, cost: float, profit: float, margin: ?float}
     */
    public function profitIfVatRegistered(): array
    {
        $rate = (float) CompanySetting::current()->vat_rate;
        $revenue = $this->project->price_basis === 'plus_vat'
            ? $this->contractValue()
            : round($this->contractValue() / (1 + $rate / 100), 2);
        $cost = round($this->projectedFinalCost() - $this->vatNotClaimable(), 2);
        $profit = round($revenue - $cost, 2);

        return ['revenue' => $revenue, 'cost' => $cost, 'profit' => $profit, 'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null];
    }

    public function budgetTotal(): float
    {
        return round(array_sum($this->budgets), 2);
    }

    public function plannedProfit(): float
    {
        return round($this->contractValue() - $this->budgetTotal(), 2);
    }

    public function costSoFar(): float
    {
        return round(array_sum($this->actuals), 2);
    }

    public function percentOfBudget(): ?float
    {
        return $this->budgetTotal() > 0 ? round($this->costSoFar() / $this->budgetTotal() * 100, 1) : null;
    }

    /**
     * Open (approved) subcontractor work orders, minus what has already been billed against them, so the
     * billed part is counted once, in cost so far.
     */
    public function committed(): float
    {
        return round($this->project->workOrders()->open()->get()->sum(fn (WorkOrder $order): float => $order->committed()), 2);
    }

    /**
     * Remaining cost beyond what is already spent or committed, so nothing is counted twice.
     * Defaults to what is left of the budget; the project can override it.
     */
    public function costToFinish(): float
    {
        if ($this->project->cost_to_finish_override !== null) {
            return round((float) $this->project->cost_to_finish_override, 2);
        }

        return round(max(0, $this->budgetTotal() - $this->costSoFar() - $this->committed()), 2);
    }

    public function costToFinishIsOverridden(): bool
    {
        return $this->project->cost_to_finish_override !== null;
    }

    public function projectedFinalCost(): float
    {
        return round($this->costSoFar() + $this->committed() + $this->costToFinish(), 2);
    }

    public function projectedProfit(): float
    {
        return round($this->contractValue() - $this->projectedFinalCost(), 2);
    }

    public function projectedMargin(): ?float
    {
        return $this->contractValue() > 0 ? round($this->projectedProfit() / $this->contractValue() * 100, 1) : null;
    }

    /**
     * Cash is shown separately from profit: money in from the client, money out for the project.
     */
    public function received(): float
    {
        return round($this->project->amountPaid(), 2);
    }

    public function paidOut(): float
    {
        return round(
            (float) $this->project->wagePayments()->sum('amount')
            + (float) VendorPayment::query()->where('project_id', $this->project->getKey())->sum('amount')
            + (float) $this->project->pettyCashClaims()->where('status', 'approved')->whereNotNull('reimbursed_on')->sum('amount'),
            2,
        );
    }

    public function cash(): float
    {
        return round($this->received() - $this->paidOut(), 2);
    }

    public function vatNotClaimable(): float
    {
        return round((float) $this->project->costs()->approved()->sum('vat_not_claimable'), 2);
    }

    /**
     * Budget vs actual per category, flagged "warning" from 80 % and "over" above 100 %.
     *
     * @return list<array{key: string, label: string, budget: float, actual: float, percent: ?float, flag: string}>
     */
    public function categories(): array
    {
        return collect(ProjectCost::CATEGORIES)
            ->map(function (string $label, string $key): array {
                $budget = $this->budgets[$key] ?? 0.0;
                $actual = $this->actuals[$key] ?? 0.0;
                $percent = $budget > 0 ? round($actual / $budget * 100, 1) : null;

                return [
                    'key' => $key,
                    'label' => $label,
                    'budget' => $budget,
                    'actual' => $actual,
                    'percent' => $percent,
                    'flag' => match (true) {
                        $budget <= 0 && $actual > 0 => 'unbudgeted',
                        $percent !== null && $percent > 100 => 'over',
                        $percent !== null && $percent >= self::WARNING_PERCENT => 'warning',
                        default => 'ok',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * % of work complete: BOQ progress when the project has a BOQ, else the manual %, else task progress.
     *
     * @return array{percent: float, source: string}
     */
    public function workComplete(): array
    {
        if (($boq = $this->project->boqProgress()) !== null) {
            return ['percent' => $boq, 'source' => 'BOQ measurements'];
        }

        if ($this->project->manual_progress !== null) {
            return ['percent' => (float) $this->project->manual_progress, 'source' => 'manual entry'];
        }

        return ['percent' => (float) $this->project->progress, 'source' => 'task progress (no BOQ or manual % yet)'];
    }

    /**
     * Green / amber / red (brief §5): budget spent % against work complete %.
     *
     * @return array{status: string, reasons: list<string>}
     */
    public function health(): array
    {
        $reasons = [];
        $status = 'green';
        $spent = $this->percentOfBudget();
        $done = $this->workComplete()['percent'];

        if ($this->contractValue() > 0 && $this->projectedFinalCost() > $this->contractValue()) {
            $status = 'red';
            $reasons[] = 'Projected final cost is above the contract value.';
        }

        if ($spent === null) {
            $reasons[] = 'No cost budget yet, so spending cannot be compared with progress.';

            return ['status' => $status === 'red' ? 'red' : 'unknown', 'reasons' => $reasons];
        }

        // Spending in categories without a budget makes "% of budget spent" meaningless (e.g. Rs 3,200 of labour
        // against a Rs 700 materials-only budget reads as 457 %), so ask for the budget instead of raising an alarm.
        $unbudgeted = collect($this->categories())->where('flag', 'unbudgeted');

        if ($unbudgeted->isNotEmpty()) {
            $reasons[] = 'Budget incomplete: '.$unbudgeted
                ->map(fn (array $category): string => $category['label'].' has Rs '.number_format($category['actual'], 2).' spent but no budget')
                ->implode('; ').'. Add '.($unbudgeted->count() === 1 ? 'its budget' : 'their budgets').' under Costs › Budget to compare spending with progress.';

            return ['status' => $status === 'red' ? 'red' : 'incomplete', 'reasons' => $reasons];
        }

        $gap = round($spent - $done, 1);

        if ($gap > 10) {
            $status = 'red';
            $reasons[] = "Spending is {$gap} points ahead of progress ({$spent}% of budget spent, {$done}% of work done).";
        } elseif ($gap >= 5) {
            $status = $status === 'red' ? 'red' : 'amber';
            $reasons[] = "Spending is {$gap} points ahead of progress.";
        }

        foreach ($this->categories() as $category) {
            if (in_array($category['flag'], ['warning', 'over'], true)) {
                $status = $status === 'red' ? 'red' : 'amber';
                $reasons[] = "{$category['label']} at {$category['percent']}% of budget.";
            }
        }

        return ['status' => $status, 'reasons' => $reasons];
    }
}
