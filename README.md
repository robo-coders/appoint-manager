# Appoint Manager

Appointment booking for small service businesses. Laravel 12, Inertia and Vue 3,
one database, one deployment, four surfaces.

The design system is in `DESIGN.md`, the decisions behind it in `DECISIONS.md`,
and deployment in `DEPLOY.md`. `npm run check` is the gate: design tokens,
contrast, component reuse and formatting.

## Surfaces

Appoint Manager is one app, one database and one deployment, served from four
hostnames:

| Host | Surface | Routes |
|---|---|---|
| `appoint-manager.com` | marketing | `routes/marketing.php` |
| `app.appoint-manager.com` | the operator app | `routes/app.php` |
| `book.appoint-manager.com/{slug}` | public booking | `routes/book.php` |
| `admin.appoint-manager.com` | super admin | `routes/admin.php` |

Webhooks and health checks are in `routes/machine.php` and are registered on
every host — they are not a user surface.

### Running locally

```bash
composer setup     # install, copy .env, key:generate, migrate, build assets
composer dev       # server + queue + logs + vite, all four in one terminal
```

Then open **<http://127.0.0.1:8000>**.

`composer dev` pins `php artisan serve --host=127.0.0.1 --port=8000`, which is
the value `.env.example` ships as `APP_URL`. The Vite dev server it starts
alongside listens on `VITE_DEV_HOST`/`VITE_DEV_PORT` — `localhost:5173` by
default — and `vite.config.js` reads both from `.env`.

Two terminals instead of one works the same way:

```bash
php artisan serve --host=127.0.0.1 --port=8000   # http://127.0.0.1:8000
npm run dev                                      # http://localhost:5173
```

**If the app renders as unstyled black-on-white HTML with dead buttons while
`npm run dev` is running, the CSP is blocking the dev server's assets — check
the browser console for `Content-Security-Policy` errors, not the network tab.**

**`APP_URL` must carry the port.** Every route and cross-surface URL is
generated from it, so `APP_URL=http://localhost` (port 80) produces links that
404 in the browser even though the pages themselves render fine. `.env.example`
ships `APP_URL=http://127.0.0.1:8000`, which is correct for `composer dev`. If
you serve on another port, change `APP_URL` to match — nothing infers it from
the request.

**By default there is no DNS setup.** With the surface variables left commented
out, every surface is served from `APP_URL` on the path prefix it used before
the split — `/`, `/diary`, `/book/{slug}`, `/admin`. `php artisan serve` and
`php artisan test` work as they always have.

Leave them **commented out**, not blank. A blank value is a value: `env()`
returns `''` for a key that is present and empty, and only falls back to its
default for a key that is absent. `config/app.php` now treats blank as unset
for these five, but the file reads more honestly with them commented.

To run with real subdomains, add the four hostnames to `/etc/hosts` and set
`APP_DOMAIN`, `SUBDOMAIN_ROUTING=true` and the four `APP_URL_*` values —
all together. Both modes are in DEPLOY.md and both are covered by tests.

### The dev-server carve-out in the CSP

`App\Http\Middleware\SecurityHeaders` sends a Content-Security-Policy whose
`script-src` and `style-src` are `'self'`. Vite's dev server is a *different*
origin — `http://localhost:5173` — so with `npm run dev` running the browser
blocks every script and stylesheet the page asks for and the app renders
unstyled and inert. Built assets are same-origin, which is why stopping the dev
server appears to "fix" it.

So the middleware adds the dev server's origin to `script-src`, `style-src` and
`connect-src` (plus the matching `ws://` origin, which HMR needs) — but only
when **both** are true:

* `app()->environment('local')`, and
* Vite's hot file exists.

The origin is read out of that hot file, which Vite writes on boot and removes
on exit. Nothing here hardcodes a port, so changing `server.port` in
`vite.config.js` needs no matching change in PHP, and the carve-out vanishes the
moment the dev server stops.

**The production policy must never contain a localhost or `ws://` origin.** That
is asserted in `tests/Feature/Security/ContentSecurityPolicyTest.php` for all
four surfaces. A permanent local carve-out that leaked to production would be a
worse outcome than a broken dev experience.

### Cross-surface links

Any URL that crosses a surface boundary must be absolute and name the right
host. Use `marketing_url()`, `app_url()`, `book_url($tenant)` and `admin_url()`
rather than writing a path by hand. `route()` already does the right thing for a
named route bound to a domain.
