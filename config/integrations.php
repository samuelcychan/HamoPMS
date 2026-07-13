<?php

use Modules\Integration\Adapters\LogChannelAdapter;

return [
    'max_attempts' => (int) env('INTEGRATION_MAX_ATTEMPTS', 3),
    'rate_limit_per_minute' => (int) env('INTEGRATION_RATE_LIMIT_PER_MINUTE', 120),

    'providers' => [
        'sandbox' => [
            'secret' => env('INTEGRATION_SANDBOX_SECRET'),
            'adapter' => LogChannelAdapter::class,
        ],
    ],
];
