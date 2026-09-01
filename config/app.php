<?php

/*
 * `env()` returns '' for a key that is present but blank, and only falls back
 * to its default for a key that is absent entirely. Every surface variable in
 * `.env.example` ships present-and-blank — the file's own comment tells you to
 * leave APP_DOMAIN empty — so `env('APP_URL_APP', env('APP_URL'))` returned ''
 * rather than APP_URL, and every cross-surface link rendered as href="".
 *
 * This treats blank as absent, which is what "leave it empty" has always meant
 * here. It is a local closure, so the cached config array stays serialisable.
 */
$blankIsUnset = static function (string $key, $default = null) {
    $value = env($key);

    return $value === null || $value === '' ? $default : $value;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Surfaces
    |--------------------------------------------------------------------------
    |
    | Appoint Manager is one app, one database and one deployment, split across four
    | hostnames so that a session on one surface can never be presented to
    | another and so each can be cached, rate limited and firewalled apart.
    |
    | When APP_DOMAIN is unset (or SUBDOMAIN_ROUTING=false) every surface is
    | served from APP_URL on its old path prefix instead, so the app and the
    | test suite run with no DNS setup at all. See DEPLOY.md.
    |
    */

    'domain' => $blankIsUnset('APP_DOMAIN'),

    'subdomain_routing' => (bool) $blankIsUnset('SUBDOMAIN_ROUTING', $blankIsUnset('APP_DOMAIN') !== null),

    'surfaces' => [
        'marketing' => $blankIsUnset('APP_URL_MARKETING', $blankIsUnset('APP_URL', 'http://localhost')),
        'app' => $blankIsUnset('APP_URL_APP', $blankIsUnset('APP_URL', 'http://localhost')),
        /*
         * Customer-facing booking links (SMS, email, the dry run) are built
         * from this, via `book_url()`. Default is APP_URL. Set APP_URL_BOOK
         * to a tunnel or LAN address when a phone has to open the link;
         * leave APP_URL, APP_URL_APP, APP_URL_ADMIN and APP_URL_MARKETING
         * alone so the operator app does not move with it. See DEPLOY.md.
         */
        'book' => $blankIsUnset('APP_URL_BOOK', $blankIsUnset('APP_URL', 'http://localhost')),
        'admin' => $blankIsUnset('APP_URL_ADMIN', $blankIsUnset('APP_URL', 'http://localhost')),
    ],

    /*
    | Comma-separated IPs or CIDR ranges. Empty means no restriction, which is
    | the right default locally and the wrong one in production.
    */
    'admin_ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
