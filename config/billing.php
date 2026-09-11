<?php

return [
    'monthly_price_pence' => 2900,
    'yearly_price_pence' => 29000,
    'yearly_label' => '2 months free',
    'plan_name' => 'Studio',
    'staff_seats' => 3,
    'invoice_prefix' => 'DD',
    'seller' => [
        'legal_name' => env('BILLING_LEGAL_NAME'),
        'address' => env('BILLING_LEGAL_ADDRESS'),
        'company_number' => env('BILLING_COMPANY_NUMBER'),
        'vat_number' => env('BILLING_VAT_NUMBER'),
    ],
    'tenant_vat_key' => 'vat_number',
    'trial_days' => 30,
    'dunning_days' => 7,
    'monthly_price_id' => env('STRIPE_PRICE_MONTHLY'),
    'yearly_price_id' => env('STRIPE_PRICE_YEARLY'),
    'billing_webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),

    'sms_included' => 200,
    'sms_topup_size' => 200,
    'sms_topup_price_pence' => 800,
    'sms_hard_ceiling' => 1000,
    'sms_warning_thresholds' => [80, 100],

    'sms_trial_included' => null,
    'sms_trial_resets_monthly' => true,

    'owner_alert_email' => env('BILLING_OWNER_EMAIL', env('MAIL_FROM_ADDRESS')),
];
