<?php

namespace App\Filament\Widgets;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Builder;

class InquiriesTrend extends MonthlyTrendChart
{
    protected static ?string $heading = 'Inquiries by month';

    protected static ?int $sort = 5;

    protected function query(): Builder
    {
        return Inquiry::query();
    }

    protected static function permission(): string
    {
        return 'view_inquiries_chart';
    }

    protected function noun(): string
    {
        return 'inquiries';
    }
}
