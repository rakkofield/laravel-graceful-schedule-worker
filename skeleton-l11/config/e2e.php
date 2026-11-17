<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E2E test switches
    |--------------------------------------------------------------------------
    |
    | Used only by this package's E2E tests.
    |
    | overlap: registers the withoutOverlapping() tasks checked by
    | WithoutOverlappingE2ETest. The test sets E2E_OVERLAP on the worker process.
    |
    | with_schedule: registers a task with withSchedule() in bootstrap/app.php,
    | checked by WithScheduleE2ETest. The test sets E2E_WITH_SCHEDULE.
    |
    */

    'overlap' => (bool) env('E2E_OVERLAP', false),

    'with_schedule' => (bool) env('E2E_WITH_SCHEDULE', false),

];
