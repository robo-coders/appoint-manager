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

# The day this suite happens on, and it is an input rather than whatever today
# is. `AppServiceProvider::freezeClockForDeterministicRuns()` reads it.
#
# Without it the screenshot baselines rot at midnight, for reasons no spec can
# reach: the demo seed walks a window of days relative to `now()`, so which of
# them are Saturdays shifts and the seeded RNG stream is consumed differently,
# and the dashboard renders the server's own date in its heading. Six snapshots
# went red every morning for no reason anybody could act on, which is worse
# than no gate at all.
#
# It must match `playwright.config.ts`, because a database seeded on one date
# and rendered on another is the same bug with extra steps. A Wednesday, so the
# demo week has an ordinary shape either side of it.
export FREEZE_NOW="${E2E_FREEZE_NOW:-2026-08-26T13:34:00+01:00}"
# Cookies must outlive the frozen clock.
#
# `FREEZE_NOW` is a fixed instant and by tomorrow it is a past one. Laravel
# stamps both the session cookie and `XSRF-TOKEN` with `now() + session.lifetime`
# — and Symfony then computes `Max-Age` from the *real* `time()`, so an expiry in
# the real past clamps to `Max-Age=0` and the browser deletes the cookie on
# receipt. Signing in rendered "419 Page Expired" in an iframe over the login
# form, which is a strange enough symptom to be worth naming.
#
# `expire_on_close` fixes only the session cookie; `XSRF-TOKEN` is built from
# the lifetime whatever that setting says, and it is the one Inertia needs. So
# the lifetime is the knob: ten years, which keeps both expiries in the real
# future for as long as this frozen date is plausibly in use. Nothing
# server-side expires on a frozen clock — the session handler compares the
# file's real mtime against a frozen cutoff, and a real mtime is always newer.
export SESSION_LIFETIME=5256000


php artisan migrate --force --no-interaction >/dev/null

# The rate limiters, emptied.
#
# A frozen clock has one consequence worth naming: `RateLimiter` stores its hit
# counts in the cache, and every cache store computes expiry from `now()`. With
# `now()` pinned, a limiter's window **never advances** — so "five login
# attempts per minute" becomes "five login attempts, ever", and the bucket
# survives between runs because the file store is on disk. The whole suite
# failed on its fourth sign-in with a 429 rendered in an iframe over the login
# form, and no amount of waiting would have cleared it.
#
# Clearing here makes the budget per *run* rather than per minute. It is a real
# budget: `auth.setup.ts` spends one for `owner@paw.test` and `logout.spec.ts`
# spends three more, which is four of five. A new spec that signs in as that
# owner is the one that breaks this, and it will look like a flake and will not
# be one.
php artisan cache:clear >/dev/null


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

# `--no-deposits` on purpose, and it is load-bearing.
#
# The demo fill now presents a tenant as Stripe-connected, because the demo
# exists to show deposit capture (DemoDataSeeder::deposits()). This suite must
# not have it: the Stripe keys above are obvious fakes, so a booking that asks
# for a deposit reaches `StripeConnectGateway`, fails, and returns 503 — where
# `slot-race.spec.ts` asserts a 201 and a 409. The fake gateway is not an option
# here either; it is reachable in `testing` only, which is AUDIT C1 and stays
# that way. So the e2e tenant takes no deposits, the booking-page snapshots say
# "pay on the day", and the deposit *copy* is asserted where it can be asserted
# honestly — `tests/Feature/Booking/ProposalPageTest.php`, against a tenant it
# marks connected itself.
php artisan demo:seed paw --no-deposits

# The console, and enough tenants for it to be worth looking at.
#
# `SuperAdminSeeder` makes the account. The three extra salons exist so the
# console screenshot shows the states the screen is built to sort on — one in
# trouble, one comped, one dark — rather than a single row of the demo tenant,
# which would prove nothing about the layout and nothing about the "needs
# attention" band. Every operator query is tenant-scoped, so no operator spec
# can see them; only the console lists every tenant.
php artisan db:seed --class=SuperAdminSeeder --no-interaction >/dev/null

php artisan tinker --execute='
    $rows = [
        ["Bramble and Co", "bramble-co", "Ines Duarte", ["subscription_status" => "past_due", "trial_ends_at" => now()->subDays(40), "booking_page_live" => true]],
        ["Clover Grooming", "clover-grooming", "Owen Blake", ["is_comped" => true, "subscription_status" => "active", "booking_page_live" => true]],
        ["Nutmeg Pet Spa", "nutmeg-pet-spa", "Rae Okonjo", ["subscription_status" => "trial", "trial_ends_at" => now()->addDays(11), "booking_page_live" => false]],
    ];

    foreach ($rows as [$name, $slug, $owner, $attributes]) {
        $tenant = App\Models\Tenant::query()->firstOrCreate(
            ["slug" => $slug],
            ["name" => $name, "timezone" => "Europe/London", "currency" => "GBP", "country" => "GB", "type" => "groomer"],
        );

        $tenant->forceFill($attributes + ["onboarding_completed_at" => now(), "last_activity_at" => now()->subDays(3)])->save();

        app(App\Support\TenantContext::class)->set($tenant);
        App\Models\User::withoutGlobalScopes()->firstOrCreate(
            ["email" => $slug . "@example.test"],
            [
                "tenant_id" => $tenant->id,
                "name" => $owner,
                "password" => Hash::make("password"),
                "role" => App\Enums\UserRole::Owner,
                "email_verified_at" => now(),
                "is_active" => true,
            ],
        );
        app(App\Support\TenantContext::class)->clear();
    }

    echo "e2e: console ready\n";
'

echo "e2e: ready"

