<?php

// Only the default store differs from the framework's config (database),
// which would need a database for the schedule mutex.
return [
    'default' => env('CACHE_STORE', 'file'),
];
