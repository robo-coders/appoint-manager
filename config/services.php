<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
         * `STRIPE_FAKE` used to live here. It is gone — AUDIT C1.
         *
         * It opted a non-production environment into `FakeStripeGateway`, which
         * accepts a literal `t=1,v1=test` webhook signature by design. That made
         * "is this box production?" a security boundary, and `APP_ENV` is a
         * string in a file. The fake now binds under `testing` and nowhere else;
         * see App\Providers\AppServiceProvider::shouldUseFakeGateways().
         *
         * Local development uses Stripe *test* keys, the same ones staging uses.
         */
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'status_webhook_url' => env('TWILIO_STATUS_URL'),
        /*
         * `X-Twilio-Signature` verification on both Twilio webhooks. Skipped
         * anyway when no token is set, which is every local and test
         * environment; this key exists so a production incident can be
         * diagnosed by turning it off deliberately rather than by editing code.
         */
        'verify_signature' => env('TWILIO_VERIFY_SIGNATURE', true),
    ],

    'plausible' => [
        'domain' => env('PLAUSIBLE_DOMAIN'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
    ],

];
