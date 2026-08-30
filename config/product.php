<?php

/*
|--------------------------------------------------------------------------
| The product's name
|--------------------------------------------------------------------------
|
| One key. Every surface that renders the name to a human reads it from here:
| the marketing wordmark and page titles, the app and admin chrome, the auth
| pages, error pages, the email templates and their plain-text twins, the PWA
| manifest, and the line item on a Stripe invoice.
|
| It is deliberately NOT `app.name`, and that is the only interesting decision
| in this file.
|
| `config('app.name')` is the machine identity, not the display name. Laravel
| slugs it into the cache prefix and the Redis prefix, and `Support\Surface`
| slugs it into the three session cookie names. Renaming the product through
| that key would rename `appoint_manager_app_session` — signing out every
| logged-in operator — cold-start the cache, and orphan the Horizon prefix, all
| as a side effect of changing a word on a web page. The repository, the
| database and the composer package are being renamed later, deliberately and
| together; a wordmark should not drag them along early.
|
| So: `product.name` is what people read, `app.name` is what machines are
| called, and the two are allowed to differ until the rename happens.
|
| `scripts/check-name.mjs` fails the build if the name is hardcoded anywhere
| user-facing, in either direction, so this stays the only copy.
|
*/

return [

    /*
     | The name, as prose. Sentence case — never all-caps, never squashed.
     |
     | Kept short on purpose: it sets on one line in a 148px navigation rail,
     | where the previous name did not and had to be stacked over two.
     */
    'name' => env('PRODUCT_NAME', 'DiaryDesk'),

];
