<?php

namespace App\Filament\Pages;

use App\Models\TaxReport;
use Filament\Pages\Page;

/**
 * Fiscal-year turnover against the VAT registration limit, VAT-bill purchases, and the VAT summary once registered.
 */
class TaxAndTurnover extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Tax & Turnover';

    protected static ?string $title = 'Tax & Turnover';

    protected static ?string $slug = 'tax-and-turnover';

    protected static string $view = 'filament.pages.tax-and-turnover';

    public int $fiscalYear;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasSitePower('view_vendor_ledger');
    }

    public function mount(): void
    {
        $this->fiscalYear = TaxReport::fiscalYearOf();
    }

    public function report(): TaxReport
    {
        return new TaxReport($this->fiscalYear);
    }

    public function previousYear(): void
    {
        $this->fiscalYear--;
    }

    public function nextYear(): void
    {
        if ($this->fiscalYear < TaxReport::fiscalYearOf()) {
            $this->fiscalYear++;
        }
    }
}
