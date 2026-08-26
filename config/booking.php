<?php

return [
    'slot_granularity_minutes' => 15,
    'min_notice_hours' => 2,
    'horizon_days' => 60,
    'availability_cache_ttl' => 60,
    'pending_hold_minutes' => 15,
    'refund_window_hours' => 48,

    /*
     * How long before an appointment is due again when neither the customer nor
     * the service says otherwise. Six weeks is the grooming default; a salon
     * sets its own per service.
     */
    'default_interval_days' => 42,
    'deleted_account_domain' => 'account-closed.invalid',
    'reminder_hours' => 48,
    'waitlist_offer_batch' => 5,
    'waitlist_offer_minutes' => 30,
];
