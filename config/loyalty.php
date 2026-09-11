<?php

return [
    'default_name' => (string) env('LOYALTY_DEFAULT_NAME', 'Loyalty card'),

    'default_visits_required' => (int) env('LOYALTY_DEFAULT_VISITS_REQUIRED', 5),

    'min_visits_required' => (int) env('LOYALTY_MIN_VISITS_REQUIRED', 2),

    'max_visits_required' => (int) env('LOYALTY_MAX_VISITS_REQUIRED', 50),

    'max_reward_description_length' => (int) env('LOYALTY_MAX_REWARD_DESCRIPTION_LENGTH', 120),

    'max_manual_note_length' => (int) env('LOYALTY_MAX_MANUAL_NOTE_LENGTH', 140),
];
