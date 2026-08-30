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
     *
     * The unit is a SEGMENT, not a message. A carrier bills per segment, and a
     * message over 160 GSM-7 characters — or containing one character outside
     * that alphabet — is two or more. Counting messages would mean a salon's
     * 200 quietly costing us 400. See `App\Support\SmsSegments`.
     */
    'sms_included' => 200,
    'sms_topup_size' => 200,
    'sms_topup_price_pence' => 800,
    /*
     * Per-tenant hard maximum this cycle, regardless of top-ups. Super admin
     * can raise it.
     *
     * 1000, five times the included pack. It was 600, which is three top-ups:
     * a busy salon buying a third one hit a wall and had to telephone us, which
     * is a poor first impression of a product sold on saving her work. 1000
     * still bounds the runaway-loop case the ceiling exists for — a loop
     * reaches it in minutes and it is £40 of spend, not £400.
     */
    'sms_hard_ceiling' => 1000,
    'sms_warning_thresholds' => [80, 100],

    /*
     * What a tenant gets before they have ever been invoiced.
     *
     * This used to be emergent rather than stated. A tenant with no Stripe
     * invoice has its cycle reset by `SmsAllowance::maybeResetCycle()` once
     * `sms_cycle_started_at` is a month old, so a sixty-day trial received two
     * included packs and nothing said so. The behaviour is kept — a long trial
     * that runs out of texts in week five is a trial that stops demonstrating
     * the feature it is meant to sell — but it is a policy now, and changing it
     * means changing these two keys rather than reasoning about invoice dates.
     *
     * `sms_trial_included` is null to mean "same as `sms_included`".
     */
    'sms_trial_included' => null,
    'sms_trial_resets_monthly' => true,

    /*
     * Who gets the hard-ceiling alert. Falls back to the platform from-address
     * so a missing env still reaches someone.
     */
    'owner_alert_email' => env('BILLING_OWNER_EMAIL', env('MAIL_FROM_ADDRESS')),
];
