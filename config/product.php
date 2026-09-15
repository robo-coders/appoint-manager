<?php

return [

    'name' => env('PRODUCT_NAME', 'DiaryDesk'),

    /*
     * The build the console's account menu names in its footer, beside the
     * environment. It is read from the deploy rather than held here: a version
     * committed to a config file is a version somebody forgets to bump, and a
     * footer that says 2.14.0 on every deploy for a year is worse than one that
     * says "dev".
     */
    'version' => env('PRODUCT_VERSION', 'dev'),

];
