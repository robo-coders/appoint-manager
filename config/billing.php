<?php

return [
    /*
     * List price. What we charge a tenant that has no override.
     * Tenant-level truth is `tenants.monthly_price_override_pence`.
     */
    'monthly_price_pence' => 3900,
    'trial_days' => 30,
    'dunning_days' => 7,
    'monthly_price_id' => env('STRIPE_PRICE_MONTHLY'),
    'billing_webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),

    /*
     * SMS is metered. Email is not.
     *
     * Included allowance resets each billing cycle. Purchased top-ups and
     * granted credit roll over — they were paid for (or given) as messages,
     * not as a monthly perk. See DECISIONS.md.
     */
    'sms_included' => 200,
    'sms_topup_size' => 200,
    'sms_topup_price_pence' => 800,
    /*
     * Per-tenant hard maximum this cycle, regardless of top-ups. Super admin
     * can raise it. Conservative: three times the included pack, not a
     * number that would cover "text every customer we have ever had".
     */
    'sms_hard_ceiling' => 600,
    'sms_warning_thresholds' => [80, 100],

    /*
     * Who gets the hard-ceiling alert. Falls back to the platform from-address
     * so a missing env still reaches someone.
     */
    'owner_alert_email' => env('BILLING_OWNER_EMAIL', env('MAIL_FROM_ADDRESS')),
];
