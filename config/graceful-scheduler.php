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
        'credentials' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        'lock_ttl' => env('SCHEDULE_SF_LOCK_TTL', 3600),

        // Seconds reserved between the Task timeout and the lock expiry, so the lock
        // release path still runs while the lock is ours. The payload carries
        // `timeoutSeconds` = expiresAt - dispatchedAt - lock_release_buffer; it becomes
        // the Task timeout only once your state machine reads it via
        // `TimeoutSecondsPath: "$.timeoutSeconds"`. Must be smaller than lock_ttl.
        'lock_release_buffer' => env('SCHEDULE_SF_LOCK_RELEASE_BUFFER', 60),

        // Floor for the derived timeout. When less than this is left of the lock
        // lifetime -- a recovery dispatch long after dueAt, or a withoutOverlapping
        // window barely wider than lock_release_buffer -- the lock can no longer bound
        // the run at all, so the timeout falls back to the lifetime the event declared
        // instead of collapsing to something that kills the task immediately.
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
