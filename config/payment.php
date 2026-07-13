<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'stripe'),

    'gateways' => [
        'stripe' => [
            'secret' => env('STRIPE_SECRET', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
        ],
    ],
];
