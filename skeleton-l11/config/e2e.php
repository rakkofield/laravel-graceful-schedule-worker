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
    */

    'overlap' => (bool) env('E2E_OVERLAP', false),

];
