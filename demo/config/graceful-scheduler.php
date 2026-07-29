<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Schedule Dispatch Method
    |--------------------------------------------------------------------------
    |
    | Specifies the dispatch method.
    |
    | Supported: "local", "stepfunctions"
    |
    */
    'dispatch' => env('SCHEDULE_DISPATCH', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Step Functions Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for using AWS Step Functions.
    |
    */
    'stepfunctions' => [
        'state_machine_arn' => env('SCHEDULE_STATE_MACHINE_ARN'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
        'version' => 'latest',
        'endpoint' => env('SFN_ENDPOINT'),
        'credentials' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        'lock_ttl' => env('SCHEDULE_SF_LOCK_TTL', 3600),

        // Seconds reserved between the Task timeout and the lock expiry, so the lock
        // release path still runs while the lock is ours. Must be smaller than lock_ttl.
        // Note: demo/stepfunctions/state-machine.json does not read `timeoutSeconds`,
        // so these two settings are inert in the demo.
        'lock_release_buffer' => env('SCHEDULE_SF_LOCK_RELEASE_BUFFER', 60),

        // Floor for the derived timeout; below it the value falls back to the lifetime
        // the event declared rather than collapsing to a value that kills the task.
        'min_task_timeout' => env('SCHEDULE_SF_MIN_TASK_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Tracker Configuration
    |--------------------------------------------------------------------------
    |
    | Execution tracking configuration.
    |
    */
    'tracker' => [
        'enabled' => env('SCHEDULE_TRACKER_ENABLED', false),
        'store' => env('SCHEDULE_TRACKER_STORE'), // redis, dynamodb, etc.
        'lock_ttl' => env('SCHEDULE_TRACKER_LOCK_TTL', 3600),      // Lock TTL in seconds
    ],
];
