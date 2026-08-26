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

`php artisan serve` and `php artisan test` work with no further setup. This is
the mode CI runs in.

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
