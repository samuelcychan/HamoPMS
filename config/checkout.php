<?php

return [
    'departure_time' => env('CHECKOUT_DEPARTURE_TIME', '11:00'),
    'late_fee' => env('CHECKOUT_LATE_FEE', '50.00'),
    'late_fee_enabled' => (bool) env('CHECKOUT_LATE_FEE_ENABLED', true),
];
