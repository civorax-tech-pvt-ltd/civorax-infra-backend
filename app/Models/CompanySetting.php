<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Company tax settings (single row). VAT registration is only a settings change:
 * whether supplier VAT is claimable is always computed from these values and the bill date.
 */
#[Fillable([
    'vat_registered', 'vat_registration_date', 'vat_rate', 'vat_registration_limit',
    'vat_warning_percent', 'fiscal_year_start_month',
])]
class CompanySetting extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'vat_registration_date' => 'date',
            'vat_rate' => 'decimal:2',
            'vat_registration_limit' => 'decimal:2',
            'vat_warning_percent' => 'integer',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * Brief §2 rule 1: supplier VAT is claimable only when the company is VAT registered, the bill is a VAT bill,
     * it is billed to the company, and it is dated on or after the registration date.
     */
    public function vatClaimable(string $billType, bool $billedToCompany, CarbonInterface|string|null $billDate): bool
    {
        return $this->vat_registered
            && $billType === 'vat'
            && $billedToCompany
            && $billDate !== null
            && $this->vat_registration_date !== null
            && Carbon::parse($billDate)->startOfDay()->gte($this->vat_registration_date->startOfDay());
    }

    /**
     * Quotations and invoices of a PAN-only company carry no VAT line (brief §2 rule 6).
     */
    public function chargesVat(): bool
    {
        return (bool) $this->vat_registered;
    }
}
