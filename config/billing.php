<?php

return [
    'monthly_price_pence' => 3900,
    'yearly_price_pence' => 39000,
    'trial_days' => 30,
    'dunning_days' => 7,
    'monthly_price_id' => env('STRIPE_PRICE_MONTHLY'),
    'yearly_price_id' => env('STRIPE_PRICE_YEARLY'),
    'billing_webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),
];
