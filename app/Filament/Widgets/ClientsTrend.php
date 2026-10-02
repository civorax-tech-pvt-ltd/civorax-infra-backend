<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

class ClientsTrend extends MonthlyTrendChart
{
    protected static ?string $heading = 'Clients by month';

    protected static ?int $sort = 6;

    protected function query(): Builder
    {
        return Client::query();
    }

    protected static function permission(): string
    {
        return 'view_clients_chart';
    }

    protected function noun(): string
    {
        return 'clients';
    }

    protected function colors(): array
    {
        return ['16, 185, 129', '99, 102, 241'];
    }
}
