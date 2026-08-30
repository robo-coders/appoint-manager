<?php

/*
|--------------------------------------------------------------------------
| Automatic rebooking
|--------------------------------------------------------------------------
|
| Everything the rebooking chase can be tuned by. `rebooking:send` runs
| hourly, so every one of these values is load-bearing: the duplicate cap is
| what stops an hourly job texting the same owner twenty-four times.
|
| Per-tenant overrides live under `tenants.settings.rebooking.*` and are read
| through the same helpers, so a value only ever has one meaning.
|
*/

return [

    /*
    | How long a subject stays off the overdue list after somebody — the job or
    | the operator pressing "Marked as contacted" — reached out. This governs
    | the *list*, not the send cap; the send cap is `attempts` below and is
    | enforced in the database.
    */
    'contacted_window_days' => 14,

    'attempts' => [
        /*
        | Messages per subject per due cycle. A due cycle is identified by the
        | date the subject fell due (last visit + interval), so booking starts
        | a new cycle and nothing else does.
        |
        | 2 = the first chase and one follow-up. Then silence: a subject who
        | has been asked twice and has not booked is a phone call, not a third
        | text. They stay on the overdue list for the salon to ring.
        */
        'max_per_cycle' => 2,

        /*
        | Days between the first chase and the follow-up.
        */
        'follow_up_gap_days' => 21,

        /*
        | Consecutive provider rejections for one subject before we stop
        | trying and flag the number for the salon to correct. A failed send
        | releases its claim so the next run retries; this is what stops that
        | retry being forever.
        */
        'max_send_failures' => 3,
    ],

    /*
    | When a chase may go out, evaluated in the TENANT's timezone — never the
    | server's. `days` is ISO-8601 (1 = Monday), so the default is weekdays.
    |
    | A subject who becomes due outside the window is not dropped: no claim is
    | made, so the next run inside the window picks them up.
    */
    'send_window' => [
        'start' => '09:00',
        'end' => '18:00',
        'days' => [1, 2, 3, 4, 5],
    ],

    'message' => [
        /*
        | The chase itself. `:salon`, `:subject`, `:due` and `:url` are
        | replaced; nothing else is.
        */
        'body' => ':salon: :subject is due :due. Book: :url',

        /*
        | Appended to every chase, and counted in the segment budget. These are
        | marketing-adjacent messages to UK consumers; the opt-out is not
        | optional and it is not a footnote.
        */
        'opt_out_suffix' => ' Reply STOP to opt out.',

        /*
        | Warn in the dry run above this many segments. One segment is 160
        | GSM-7 characters, or 70 if any character is outside GSM-7.
        */
        'warn_above_segments' => 1,

        /*
        | Runaway guard, not a formatting rule. A message above this is
        | shortened from the front — never the tail, because the tail is the
        | booking link and the opt-out. Set high enough that a real salon name
        | and a real dog's name never reach it.
        */
        'max_segments' => 3,
    ],

    /*
    | Inbound keywords. Twilio handles its own standard set at the number
    | level; we handle them at ours too, because we must never queue a message
    | we already know is unwanted. Matched case-insensitively on the trimmed
    | body, with surrounding punctuation stripped.
    */
    'opt_out_keywords' => ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'],

    'opt_in_keywords' => ['start', 'unstop'],

    /*
    | What we text back. Twilio already replies to its own standard keywords on
    | the number, so an empty string here means "say nothing and do not spend a
    | segment saying it".
    */
    'opt_out_reply' => '',

    'opt_in_reply' => '',

    /*
    | Rows on the overdue page's send log. Long enough to cover a cycle's worth
    | of chases, short enough that the page is still one screen.
    */
    'send_log_rows' => 20,
];
