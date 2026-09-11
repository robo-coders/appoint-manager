<?php

return [

    'contacted_window_days' => 14,

    'attempts' => [
        'max_per_cycle' => 2,

        'follow_up_gap_days' => 21,

        'max_send_failures' => 3,
    ],

    'send_window' => [
        'start' => '09:00',
        'end' => '18:00',
        'days' => [1, 2, 3, 4, 5],
    ],

    'message' => [
        'body' => ':salon: :subject is due :due. Book: :url',

        'opt_out_suffix' => ' Reply STOP to opt out.',

        'warn_above_segments' => 1,

        'max_segments' => 3,
    ],

    'opt_out_keywords' => ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'],

    'opt_in_keywords' => ['start', 'unstop'],

    'opt_out_reply' => '',

    'opt_in_reply' => '',

    'send_log_rows' => 20,
];
