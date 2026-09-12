<?php

return [

    'default' => env('TENANT_DEFAULT_CURRENCY', 'GBP'),

    'supported' => [

        'GBP' => [
            'symbol' => '£',
            'label' => 'British pound',
            'countries' => [
                'GB' => 'United Kingdom',
            ],
        ],

        'EUR' => [
            'symbol' => '€',
            'label' => 'Euro',
            'countries' => [
                'AT' => 'Austria',
                'BE' => 'Belgium',
                'CY' => 'Cyprus',
                'DE' => 'Germany',
                'EE' => 'Estonia',
                'ES' => 'Spain',
                'FI' => 'Finland',
                'FR' => 'France',
                'GR' => 'Greece',
                'IE' => 'Ireland',
                'IT' => 'Italy',
                'LT' => 'Lithuania',
                'LU' => 'Luxembourg',
                'LV' => 'Latvia',
                'MT' => 'Malta',
                'NL' => 'Netherlands',
                'PT' => 'Portugal',
                'SI' => 'Slovenia',
                'SK' => 'Slovakia',
            ],
        ],

        'USD' => [
            'symbol' => '$',
            'label' => 'US dollar',
            'countries' => [
                'US' => 'United States',
            ],
        ],

    ],

];
