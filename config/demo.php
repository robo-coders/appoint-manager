<?php

return [
    /*
     * How much trial remains after `demo:rebooking`, counted from the run.
     *
     * A hardcoded calendar date made a November seed look like a salon whose
     * trial had already expired. Twenty-one days is visibly still in trial
     * with time left, and it is not `billing.trial_days` — that key is what a
     * real signup gets, and changing it must not silently move the demo.
     */
    'trial_days' => 21,
];
