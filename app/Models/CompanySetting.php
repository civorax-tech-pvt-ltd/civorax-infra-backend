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
    'help_enabled', 'help_phone', 'help_whatsapp', 'help_email', 'help_website', 'help_facebook', 'help_hours',
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
            'help_enabled' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public static function current(): self
    {
        $settings = static::query()->firstOrCreate(['id' => 1]);

        // A new row only gets the column defaults (VAT rate, help contacts) once read back.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    /**
     * Contacts for the floating "Need help?" box; empty ones are left out.
     *
     * @return list<array{type: string, label: string, href: string}>
     */
    public function helpContacts(): array
    {
        $whatsapp = preg_replace('/\D/', '', (string) $this->help_whatsapp);
        $website = (string) $this->help_website;

        return array_values(array_filter([
            filled($this->help_phone) ? ['type' => 'phone', 'label' => $this->help_phone, 'href' => 'tel:'.preg_replace('/[^\d+]/', '', $this->help_phone)] : null,
            filled($whatsapp) ? ['type' => 'whatsapp', 'label' => 'WhatsApp', 'href' => 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Namaste CivoraX, I need help with the portal.')] : null,
            filled($this->help_email) ? ['type' => 'email', 'label' => $this->help_email, 'href' => 'mailto:'.$this->help_email] : null,
            filled($website) ? ['type' => 'website', 'label' => preg_replace('#^https?://(www\.)?#', 'www.', rtrim($website, '/')), 'href' => $website] : null,
            filled($this->help_facebook) ? ['type' => 'facebook', 'label' => 'CivoraX Infra Pvt. Ltd.', 'href' => $this->help_facebook] : null,
        ]));
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
