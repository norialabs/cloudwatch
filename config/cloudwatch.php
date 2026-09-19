<?php

declare(strict_types=1);

return [

    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    'log_group' => env('CLOUDWATCH_LOG_GROUP', env('APP_NAME', 'laravel')),
    'log_stream' => env('CLOUDWATCH_LOG_STREAM', '{app}-{env}'),

    'retention' => env('CLOUDWATCH_LOG_RETENTION', 30),

    'batch_size' => env('CLOUDWATCH_BATCH_SIZE', 25),

    'level' => env('CLOUDWATCH_LOG_LEVEL', 'debug'),

    'tags' => [],

    'fallback_path' => env('CLOUDWATCH_FALLBACK_PATH'),

    'fallback_days' => env('CLOUDWATCH_FALLBACK_DAYS', 14),
];
