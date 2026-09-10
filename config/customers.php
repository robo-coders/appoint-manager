<?php

return [
    'reliable_no_show_threshold' => (float) env('CUSTOMERS_RELIABLE_NO_SHOW_THRESHOLD', 0.1),

    'min_visits_for_reliability_label' => (int) env('CUSTOMERS_MIN_VISITS_FOR_RELIABILITY_LABEL', 3),

    'watch_no_show_count' => (int) env('CUSTOMERS_WATCH_NO_SHOW_COUNT', 2),

    'watch_window_months' => (int) env('CUSTOMERS_WATCH_WINDOW_MONTHS', 3),

    'attendance_strip_length' => (int) env('CUSTOMERS_ATTENDANCE_STRIP_LENGTH', 10),

    'ledger_page_size' => (int) env('CUSTOMERS_LEDGER_PAGE_SIZE', 10),

    'ledger_expanded_page_size' => (int) env('CUSTOMERS_LEDGER_EXPANDED_PAGE_SIZE', 100),
];
