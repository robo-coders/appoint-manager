<?php

$blankIsUnset = static function (string $key, $default = null) {
    $value = env($key);

    return $value === null || $value === '' ? $default : $value;
};

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'domain' => $blankIsUnset('APP_DOMAIN'),

    'subdomain_routing' => (bool) $blankIsUnset('SUBDOMAIN_ROUTING', $blankIsUnset('APP_DOMAIN') !== null),

    'surfaces' => [
        'marketing' => $blankIsUnset('APP_URL_MARKETING', $blankIsUnset('APP_URL', 'http://localhost')),
        'app' => $blankIsUnset('APP_URL_APP', $blankIsUnset('APP_URL', 'http://localhost')),
        'book' => $blankIsUnset('APP_URL_BOOK', $blankIsUnset('APP_URL', 'http://localhost')),
        'admin' => $blankIsUnset('APP_URL_ADMIN', $blankIsUnset('APP_URL', 'http://localhost')),
    ],

    'admin_ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')),
    ))),

    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
