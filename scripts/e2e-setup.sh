#!/usr/bin/env bash
#
# Build the database the end-to-end suite runs against.
#
# **MySQL, not SQLite, and that is the whole point.** The 409 slot race is
# decided by `lockForUpdate()` in `BookingService::lockStaffWindow()`, and
# `lockForUpdate()` is a no-op on SQLite — DECISIONS.md has said so since the
# booking engine was written. Two concurrent bookings against SQLite therefore
# produce two 201s about as often as they produce a 201 and a 409: both
# transactions read before either writes, the in-transaction re-check passes
# twice, and the double booking is created.
#
# That is not a bug in the application. It is the reason the deployment target
# is MySQL. But it does mean an end-to-end race test on SQLite asserts nothing —
# it would pass or fail on timing, and "flaky" is what people call a test that is
# measuring the wrong thing.
#
# So the e2e suite gets its own MySQL database, migrated and seeded from
# scratch. It is dropped and rebuilt every run: these specs make real bookings,
# and a suite whose result depends on what the previous run left behind is a
# suite that fails on the second Tuesday.
#
set -euo pipefail

DB="${E2E_DB_DATABASE:-appoint_manager_e2e}"
USER="${E2E_DB_USERNAME:-root}"
PASS="${E2E_DB_PASSWORD:-}"
HOST="${E2E_DB_HOST:-127.0.0.1}"
PORT="${E2E_DB_PORT:-3306}"

mysql_args=(-h "$HOST" -P "$PORT" -u "$USER")
[ -n "$PASS" ] && mysql_args+=("-p$PASS")

if ! mysqladmin "${mysql_args[@]}" ping >/dev/null 2>&1; then
    echo "e2e: MySQL is not reachable at $HOST:$PORT."
    echo "e2e: the slot race cannot be tested on SQLite — lockForUpdate() is a no-op there."
    echo "e2e: start MySQL, or set E2E_DB_* to point at one."
    exit 1
fi

echo "e2e: rebuilding $DB"
mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

export DB_CONNECTION=mysql DB_HOST="$HOST" DB_PORT="$PORT" DB_DATABASE="$DB" DB_USERNAME="$USER" DB_PASSWORD="$PASS"
# Matches playwright.config.ts. Every cross-surface URL is built from this.
export APP_URL="${E2E_APP_URL:-http://127.0.0.1:8129}"
# The gateways refuse to boot without these (AUDIT C1). Obvious fakes: no spec
# reaches a card, and a key that looks real is a key somebody eventually uses.
export STRIPE_KEY=pk_test_e2e STRIPE_SECRET=sk_test_e2e STRIPE_WEBHOOK_SECRET=whsec_e2e
export STRIPE_BILLING_WEBHOOK_SECRET=whsec_billing_e2e STRIPE_PRICE_MONTHLY=price_e2e_monthly

php artisan migrate --force --no-interaction >/dev/null

# One salon, seeded the way the app would create it, then filled with the demo
# week. `demo:seed` fills a tenant that already exists; it does not create one.
php artisan tinker --execute='
    $tenant = App\Models\Tenant::query()->firstOrCreate(
        ["slug" => "paw"],
        [
            "name" => "Paw & Order",
            "timezone" => "Europe/London",
            "currency" => "GBP",
            "country" => "GB",
            "type" => "groomer",
            // A new salon starts with its booking page dark; the public routes
            // 404 until it goes live. The e2e suite books through that page, so
            // this tenant is live from the start.
            "booking_page_live" => true,
        ],
    );

    /*
     * Both flags, and both are needed: `ResolvePublicTenant` 404s a salon whose
     * onboarding is unfinished as well as one whose page is dark. A tenant made
     * by hand has neither.
     */
    $tenant->forceFill([
        "booking_page_live" => true,
        "onboarding_completed_at" => now(),
    ])->save();

    /*
     * The tenant context has to be set before the owner is written.
     * `BelongsToTenant` refuses to create a User without one — deliberately, so
     * a stray write can never land in the wrong salon — and a bare tinker call
     * has no context at all.
     */
    app(App\Support\TenantContext::class)->set($tenant);

    App\Models\User::withoutGlobalScopes()->firstOrCreate(
        ["email" => "owner@paw.test"],
        [
            "tenant_id" => $tenant->id,
            "name" => "Rosa Adeyemi",
            "password" => Illuminate\Support\Facades\Hash::make("password"),
            "role" => App\Enums\UserRole::Owner,
            "email_verified_at" => now(),
            "is_active" => true,
        ],
    );

    echo "e2e: owner ready\n";
'

php artisan demo:seed paw

echo "e2e: ready"
