<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Labour wages
    |--------------------------------------------------------------------------
    |
    | A normal working day is this many hours; overtime is paid pro rata:
    | overtime hours × (daily wage ÷ hours_per_day).
    |
    */

    'hours_per_day' => (int) env('SITE_HOURS_PER_DAY', 8),

    /*
    |--------------------------------------------------------------------------
    | Daily reminder
    |--------------------------------------------------------------------------
    |
    | Time (Nepal time) the team is reminded about today's site report on
    | projects that are in execution.
    |
    */

    'reminder_time' => env('SITE_REMINDER_TIME', '18:00'),

    /*
    |--------------------------------------------------------------------------
    | Monthly stock count
    |--------------------------------------------------------------------------
    |
    | Day of the month (1-28) on which site teams are reminded to count key
    | materials left on site.
    |
    */

    'stock_count_day' => (int) env('SITE_STOCK_COUNT_DAY', 1),

];
