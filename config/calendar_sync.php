<?php

return [
    'token_length' => 40,
    'ics_cache_seconds' => 60,
    'rate_limit_per_minute' => 30,
    'first_sync_note_hours' => 12,
    'window_days' => 120,
    'trailing_hours' => 24,
    'uid_domain' => env('CALENDAR_SYNC_UID_DOMAIN', 'diarydesk.com'),
];
