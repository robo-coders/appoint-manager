<?php

/*
|--------------------------------------------------------------------------
| Facts about the business, for structured data
|--------------------------------------------------------------------------
|
| The `LocalBusiness` and `Service` JSON-LD on the marketing site needs a
| handful of facts about us that are not prices and are therefore not on
| `config/billing.php`. They live here rather than inside
| `App\Support\MarketingSchema` for one reason: a value a search engine
| publishes about a business must be changeable without editing a class that
| also decides *how* it is published.
|
| **There is deliberately no street address.** `LocalBusiness` allows a partial
| `PostalAddress`, and locality plus region plus country is all that is true —
| there is no shopfront and no trading address to give. Inventing one to fill
| the shape of the schema would be publishing a false fact about a real business
| to the search engines that quote it, which is worse than an incomplete record.
| Likewise no `telephone`: there is no business line, and the contact route is
| the form and the email address on `/contact`.
|
| Every string here is already prose somewhere on the site (the footer's "Built
| in East Kilbride, Scotland", `/about`, `/contact`). Those are sentences and
| stay sentences; this is the machine-readable copy of the same facts.
|
*/

return [

    // Where the business is, as a partial PostalAddress.
    'locality' => env('MARKETING_LOCALITY', 'East Kilbride'),
    'region' => env('MARKETING_REGION', 'Scotland'),
    'country' => env('MARKETING_COUNTRY', 'GB'),

    /*
     | Who it is sold to, for `areaServed`. The product is not geofenced — the
     | in-person setup offer is local, the software is not — so this is the
     | country rather than the town.
     */
    'area_served' => env('MARKETING_AREA_SERVED', 'GB'),

    /*
     | `Organization.foundingDate`. Used only as a fact about the business;
     | nothing on the site renders it.
     */
    'founded' => env('MARKETING_FOUNDED', '2026'),

];
