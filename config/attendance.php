<?php

return [

    /*
    | How many full months of detailed attendance (daily records, places visited and GPS
    | readings) to keep before the current month. Older detail is deleted nightly; the
    | monthly summaries are kept for good.
    */

    'keep_months' => (int) env('ATTENDANCE_KEEP_MONTHS', 3),

];
