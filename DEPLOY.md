# Deploy

## Hostnames

Appoint Manager is **one app, one database, one deployment** served from four
hostnames. This is not a microservice split and must not become one.

| Host | Surface | Who |
|---|---|---|
| `appoint-manager.com` | marketing | anyone |
| `app.appoint-manager.com` | the operator app | the salon owner |
| `book.appoint-manager.com/{slug}` | public booking | her customers |
| `admin.appoint-manager.com` | super admin | us |

Verticals do **not** get subdomains — a dentist logs into `app.appoint-manager.com`
exactly like a groomer, and only the vertical config and the marketing path she
arrived from differ. Tenants do not get subdomains either; the slug stays in the
path. Wildcard subdomains mean wildcard SSL and a class of routing bugs, for no
gain.

### DNS

Four A/AAAA records (or `appoint-manager.com` plus three CNAMEs) pointing at the same
server:

```
appoint-manager.com.         A     <server-ip>
www.appoint-manager.com.     CNAME appoint-manager.com.      # redirect to apex at the edge
app.appoint-manager.com.     CNAME appoint-manager.com.
book.appoint-manager.com.    CNAME appoint-manager.com.
admin.appoint-manager.com.   CNAME appoint-manager.com.
```

### SSL

One certificate covering all four names, or four certificates — **not** a
wildcard. On Forge, add each hostname to the site and issue a single Let's
Encrypt certificate with all four SANs:

```
appoint-manager.com, www.appoint-manager.com, app.appoint-manager.com, book.appoint-manager.com, admin.appoint-manager.com
```

Renewal covers all four together. If you add a hostname later you must reissue.

### Web server

One site, one document root, all four hostnames as aliases. Nginx:

```
server_name appoint-manager.com www.appoint-manager.com app.appoint-manager.com book.appoint-manager.com admin.appoint-manager.com;
```

Laravel routes by `Host`, so nothing else is needed. Make sure the proxy passes
the original host (`proxy_set_header Host $host`) or every request resolves to
the wrong surface.

### Environment

```
APP_DOMAIN=appoint-manager.com
SUBDOMAIN_ROUTING=true
APP_URL=https://app.appoint-manager.com
APP_URL_MARKETING=https://appoint-manager.com
APP_URL_APP=https://app.appoint-manager.com
APP_URL_BOOK=https://book.appoint-manager.com
APP_URL_ADMIN=https://admin.appoint-manager.com

# Restrict the console to the office and the two of us. Empty means no
# restriction, which is wrong in production.
ADMIN_IP_ALLOWLIST=203.0.113.4,198.51.100.0/24

SESSION_SECURE_COOKIE=true
```

`SESSION_DOMAIN` is **not** set: it is assigned per request from the resolved
host, so a cookie is never scoped to `.appoint-manager.com` where all four surfaces
could read it.

### Caching

`book.appoint-manager.com` is the only surface that may be CDN-cached, and only its
static assets — the booking page itself is per tenant and must not be cached at
the edge. If you put a CDN in front, set the cache key to include the full path
and never cache a response carrying `Set-Cookie`.

Marketing sends `Cache-Control: public, max-age=300`. Nothing on it varies by
session, and there is a test asserting that.

`app.` and `admin.` must never be cached.

---

## Local development

**Default: no DNS setup at all.** With `APP_DOMAIN` empty, every surface is
served from `APP_URL` on the path prefix it used before the split:

| Surface | Local path |
|---|---|
| marketing | `/`, `/pricing`, … |
| operator app | `/diary`, `/bookings`, … |
| public booking | `/book/{slug}` |
| super admin | `/admin` |

`php artisan serve` works with no further setup. `php artisan test` needs the
test database below. This is the mode CI runs in.

**The suite runs in parallel.** `composer test` and `npm run test:php` both pass
`--parallel`; on eight cores that is 5.3s against 14.8s serial, and the gap only
widens. Serial (`vendor/bin/pest`) still works and is the better mode for
reading a failure, because parallel interleaves output from eight workers.
Parallel workers create `appoint_manager_test_test_1`, `_2`, … themselves.

---

## Test database

The Pest suite is MySQL 8, same as local and production. It is **not** SQLite
and it is **not** the development database. `phpunit.xml` forces:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=appoint_manager_test
DB_USERNAME=root
DB_PASSWORD=
```

`force="true"` so a shell that exported `DB_DATABASE=appoint_manager` cannot
point `migrate:fresh` at the salon you are working on.

### Fresh clone (Docker)

`brew` is not a path this project documents — it is broken on at least one
machine here. Docker is.

```bash
docker compose up -d
./scripts/test-setup.sh
./vendor/bin/pest
```

`docker compose up -d` starts MySQL 8.4 and creates three empty databases on
first boot (`docker/mysql/init.sql`): `appoint_manager`, `appoint_manager_test`,
`appoint_manager_e2e`. `scripts/test-setup.sh` is then a no-op on the CREATE
and runs `php artisan migrate` against `appoint_manager_test` so a failed first
test is a setup problem, not a red herring mid-suite. RefreshDatabase will
`migrate:fresh` on its own after that.

If port 3306 is already taken, change the left-hand port in `docker-compose.yml`
and the `DB_PORT` values in `.env` **and** `phpunit.xml`. phpunit.xml wins.

### Already running MySQL

```bash
mysqladmin -h 127.0.0.1 -P 3306 -u root ping
./scripts/test-setup.sh
./vendor/bin/pest
```

`scripts/test-setup.sh` is:

```bash
mysql -h 127.0.0.1 -P 3306 -u root \
  -e "CREATE DATABASE IF NOT EXISTS appoint_manager_test
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
DB_CONNECTION=mysql DB_DATABASE=appoint_manager_test \
  php artisan migrate --force
```

Override host / user / password with `TEST_DB_HOST`, `TEST_DB_USERNAME`,
`TEST_DB_PASSWORD` if yours are not root / empty. phpunit.xml still has to
agree — it forces those three values.

Do not run the suite against `appoint_manager`. Do not run it against
`appoint_manager_e2e`. Those are the local salon and the Playwright seed.

---

The two modes have to agree, and they did not used to: a helper declared in one
test file and called from another exists only after that file is loaded, which
is always true serially and is a fatal in whichever worker did not get it.
Shared fixtures live in `tests/Pest.php`, and `TestHelperScopeTest` fails the
build if one ever moves out again.

**Optional: subdomains locally.** Add to `/etc/hosts`:

```
127.0.0.1 appoint-manager.test app.appoint-manager.test book.appoint-manager.test admin.appoint-manager.test
```

then in `.env`:

```
APP_DOMAIN=appoint-manager.test
SUBDOMAIN_ROUTING=true
APP_URL=http://app.appoint-manager.test
APP_URL_MARKETING=http://appoint-manager.test
APP_URL_APP=http://app.appoint-manager.test
APP_URL_BOOK=http://book.appoint-manager.test
APP_URL_ADMIN=http://admin.appoint-manager.test
```

`php artisan serve` binds one port, so use `valet`/`herd` or run
`php artisan serve --host=0.0.0.0 --port=80` to reach all four names.

Both modes are covered by tests: `tests/Feature/Surfaces/SurfaceRoutingTest.php`
runs with subdomains on, `PathFallbackTest.php` with them off.

---

## Demo deposits on test keys

Deposit capture is what this product sells, so the demo tenant has to take one
end to end — card form, confirmation, the booking flipping to `confirmed` off
the webhook. Doing that needs four things, and `demo:seed` refuses to run with
deposits on until all four are present. There is no fake-gateway shortcut:
`FakeStripeGateway` binds under `testing` and nowhere else (AUDIT C1), so local
development uses real Stripe **test-mode** keys or it uses `--no-deposits`.

Everything below is test mode. No real card is ever charged and no real money
moves; test-mode keys cannot touch live data even by mistake.

### 1. The two API keys

<https://dashboard.stripe.com/test/apikeys> — make sure the **Test mode** toggle
is on, top right. Copy both into `.env`:

```
STRIPE_KEY=pk_test_…      # publishable. The card form on the booking page mounts with this.
STRIPE_SECRET=sk_test_…   # secret. PaymentIntents, refunds and the Connect account are created with this.
```

`pk_test_` and `sk_test_` are the prefixes to check for. Anything starting
`pk_live_` or `sk_live_` is the wrong pair and must never be in a local `.env`.

### 2. The webhook signing secret

The browser never confirms a paid booking — only `payment_intent.succeeded`
does (DECISIONS.md, "Stripe"). So a deposit that is paid but whose webhook never
arrives stays `pending` and is released after fifteen minutes. Locally, Stripe
reaches you through the CLI:

```bash
brew install stripe/stripe-cli/stripe    # once
stripe login                             # once, opens the browser
stripe listen --forward-to http://localhost:8000/stripe/webhook
```

Leave that running. It prints the signing secret on the first line:

```
STRIPE_WEBHOOK_SECRET=whsec_…
```

The port must match `APP_URL`. If you run subdomains locally, forward to the
**booking** host — `/stripe/webhook` is registered in `routes/machine.php` with
no host constraint, so any of the four works, but use the one you are browsing.

Platform billing has its own endpoint and its own secret. Only set these if you
are working on the subscription flow rather than on deposits:

```bash
stripe listen --forward-to http://localhost:8000/stripe/billing/webhook
# STRIPE_BILLING_WEBHOOK_SECRET=whsec_…
# STRIPE_PRICE_MONTHLY=price_…   from https://dashboard.stripe.com/test/products
```

### 3. A test-mode connected account

Salon deposits are **direct charges on the connected account**, so the demo
tenant needs a connected account that Stripe has actually heard of. The seeded
placeholder — `acct_demo_not_a_real_account` — is not one, and
`StripeConnectGateway` rejects it.

Create one once, with the keys from step 1 already in `.env`:

```bash
php artisan tinker --execute="
  \$stripe = new Stripe\StripeClient(config('services.stripe.secret'));
  \$account = \$stripe->accounts->create([
      'type' => 'express',
      'country' => 'GB',
      'email' => 'demo@example.com',
      'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
  ]);
  echo \$account->id, PHP_EOL;
"
```

That prints `acct_…`. A freshly created Express account has `charges_enabled =
false` until its onboarding is finished, and an account that cannot take charges
cannot take a deposit. Finish it in the hosted flow:

```bash
php artisan tinker --execute="
  \$stripe = new Stripe\StripeClient(config('services.stripe.secret'));
  echo \$stripe->accountLinks->create([
      'account' => 'acct_…',
      'type' => 'account_onboarding',
      'refresh_url' => 'http://localhost:8000/settings/payments',
      'return_url' => 'http://localhost:8000/settings/payments',
  ])->url, PHP_EOL;
"
```

Open that URL and walk through it. In test mode every field takes a test value —
Stripe offers to prefill the whole form, and the test phone code is `000000`.
Sort code `10-88-00`, account number `00012345` for the GB bank details.

Check it took:

```bash
php artisan tinker --execute="
  echo (new Stripe\StripeClient(config('services.stripe.secret')))
      ->accounts->retrieve('acct_…')->charges_enabled ? 'ready' : 'not ready yet';
"
```

Keep that `acct_…` somewhere. It is reusable forever and creating a second one
does nothing useful.

### 4. Seed the tenant against it

```bash
php artisan demo:seed willow-street-grooming --stripe-account=acct_…
```

With anything missing this fails before it writes a row and prints exactly what
is absent. `--no-deposits` is the deliberate way to skip all of the above —
`scripts/e2e-setup.sh` uses it, because that suite books through the public page
against obvious fake keys and a tenant asking for a deposit would 503 where the
slot-race spec expects a 201.

### Walking it

Open the booking page, pick a service with a deposit, and confirm. The page
should show `£35.00 total, £10.00 deposit due today`, then a card field.

| Card | Result |
|---|---|
| `4242 4242 4242 4242` | succeeds |
| `4000 0025 0000 3155` | requires 3D Secure — use it to check the authentication step |
| `4000 0000 0000 9995` | declined, insufficient funds |

Any future expiry, any CVC, any postcode. Watch the `stripe listen` window: you
want `payment_intent.succeeded` forwarded and answered `200`. The booking goes
`pending → confirmed` on that event and not before, so if the CLI is not running
the booking will sit pending and be released after fifteen minutes — which is
correct behaviour and looks exactly like a bug.

---

## Sessions

Each surface names and scopes its own cookie, assigned before the session is
opened (`ConfigureSurfaceSession`):

| Surface | Cookie | Scope |
|---|---|---|
| `app.` | `appoint_manager_app_session` | that host only |
| `admin.` | `appoint_manager_admin_session` | that host only |
| `book.` | no auth session | that host only |

**No cookie is ever set on `.appoint-manager.com`.** A session on one surface
cannot be presented to another, which is the point of the split.

Impersonation is the one flow that crosses the boundary. The console cannot set
a cookie for the app host, so it issues a 60-second single-use signed link which
the app surface exchanges for a normal session tagged `impersonator_id`.
Exiting returns to the console. Both ends write an audit row.

---

## Release

Zero-downtime on Laravel Forge:

1. `git pull`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
6. Reload PHP-FPM (Forge does this)
7. Restart queue workers so they pick up new code

**`route:cache` bakes in the hostnames**, so a change to any `APP_URL_*` needs a
`route:clear && route:cache`, not just `config:clear`.

Queue: two `supervisor` workers on `redis` (`default` + `notifications`),
`tries=3`, timeout 90s. Alert on `failed_jobs` via the console's failures page.

## Rollback

1. `git checkout` the previous release tag
2. Repeat install/build (skip migrate if the release added no columns)
3. If a migration must be undone, run the matching `down` in a maintenance
   window — do not migrate down blindly on a live diary
4. `php artisan route:clear && php artisan route:cache`
5. Restart workers
6. Confirm `GET /health` returns `ok: true` on every hostname

## Monitoring

Ping `GET /health` on **each** hostname from a third-party monitor every 60s —
a DNS or certificate problem on one surface will not show up on another. `/up`
remains the framework liveness probe.

## Maintenance mode

`php artisan down`, and **not** `php artisan down --render=...`.

`--render` pre-renders the view once, in the CLI, and serves that snapshot to
everybody. It is faster per request and it is wrong here: the console has no
request, so `App\Support\ErrorPage` resolves the surface as the operator app
and every visitor gets the operator's wording — including a customer on the
booking host, who is told the product "is being updated" rather than that
calling the salon is faster than waiting.

Plain `down` throws per request, so `errors::503` renders live and says the
right thing to whoever is reading it. It costs one Blade render and no query:
the page makes no database call, reads no Vite manifest and mounts no Inertia,
which is verified in `tests/Feature/Errors/ErrorPageTest.php` and was checked by
hand against a stopped MySQL.
