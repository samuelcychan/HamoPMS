<?php

return [
    'default' => env('CANCELLATION_DEFAULT_POLICY', 'standard'),

    'policies' => [
        'flexible' => [
            'free_cancellation_hours' => (int) env('CANCELLATION_FLEXIBLE_FREE_HOURS', 24),
            'penalty_type' => 'first_night',
            'penalty_value' => null,
            'non_refundable' => false,
        ],
        'standard' => [
            'free_cancellation_hours' => (int) env('CANCELLATION_STANDARD_FREE_HOURS', 48),
            'penalty_type' => 'first_night',
            'penalty_value' => null,
            'non_refundable' => false,
        ],
        'non_refundable' => [
            'free_cancellation_hours' => 0,
            'penalty_type' => 'percentage',
            'penalty_value' => 100,
            'non_refundable' => true,
        ],
    ],
];
