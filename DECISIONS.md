# Decisions

## Public booking URL
Production can use `book.{APP_DOMAIN}/{slug}` when `BOOKING_HOST` is set. Local (and as a universal fallback) uses `/book/{slug}` so tenant slugs cannot collide with admin routes such as `/services` or `/staff`.

## Availability engine isolation
The engine never reads `TenantContext`. Every query uses `withoutGlobalScopes()` plus an explicit `tenant_id` so it stays pure and callable from tests, jobs, and the public site.

## Slot alignment
Candidate starts are rounded **up** to the tenant's granularity on the **local** clock (so a 09:00–17:00 salon gets 09:00, 09:15… not UTC-shifted :07 times). All comparisons stay on UTC instants.

## Existing-booking buffers
A held interval is `[starts_at - requested_service.buffer, ends_at + requested_service.buffer)`. Cancelled bookings do not block slots. Pending and confirmed do.

## Unassigned services
A service with no staff on `service_user` yields zero public slots.

## Availability cache
Array/file cache cannot use tags. Keys are `availability:{tenant}:{service}:{from}:{to}:{staff}` plus a per-tenant version integer. Any booking write increments the version.

## Double-booking test on SQLite
`lockForUpdate()` is a no-op on sqlite memory. The HTTP test books once, then a second POST to the same slot returns 409. A unit test injects a competing insert after the lock (via `BookingService` hook) to prove the in-lock re-check; that insert lives in the same transaction so a thrown `SlotUnavailableException` rolls it back. Real row locks apply on MySQL.

## Manual diary bookings
`source = manual`, `status = confirmed`, `deposit_status = none`. Online public bookings stay `pending` / `required` until Stripe (prompt 3).

## DST tests
UK 2026-03-29 (spring) and 2026-10-25 (autumn), using a 00:00–04:00 local rule so the missing/repeated hour is inside the window. 09:00–17:00 is also asserted to still mean wall-clock 09:00–17:00 on those dates.

## Stripe
Connect Express with **direct charges** on the connected account (`Stripe-Account` header). `platform_fee_bps` is stored and applied as `application_fee_amount` only when greater than 0. Stripe is wrapped in `StripeGateway`; tests use `FakeStripeGateway`. The pending booking is committed first; the PaymentIntent is created afterwards and attached. If Stripe fails, the 15-minute expiry job releases the slot — the row lock is never held across the network.

Unconnected tenants, or services with a £0 deposit, confirm immediately with no PaymentIntent. The browser never confirms a paid booking — only `payment_intent.succeeded`.

Pending checkout holds expire after 15 minutes via `bookings:release-expired`.

## Refunds
`settings.booking.refund_window_hours` default 48. Cancel is always allowed until start; refunds only outside that window. Reschedule is allowed until the same cutoff. Deposit carries over on reschedule — no second charge.

## Notifications
SMS goes through `SmsGateway` (Twilio or log). Tests bind `RecordingSmsGateway`. Reminders are delayed jobs keyed on the booking; the job no-ops if cancelled. Sync queue ignores delay, so reminder tests fake the bus and invoke the job directly.

## Phone numbers
Stored E.164 via libphonenumber, region from `tenants.country` (default `GB`). Possible numbers are accepted so UK test ranges still normalise.

## Waitlist
Customer cancel always offers the slot. Admin cancel previews the blast and can send or skip. Ranking: tighter time preference first, then oldest `created_at`, then id. Claim uses the same booking lock; siblings expire immediately.

## Filled-from-waitlist
`bookings.waitlist_entry_id` set on claim. Dashboard counts those rows — not a denormalised counter.

## Platform billing vs Connect
Salon deposits stay on Stripe Connect (`/stripe/webhook`, `stripe_account_id`). Our SaaS subscription is a second Stripe integration on the platform account: Checkout + `/stripe/billing/webhook` + `billing_events` (idempotent, separate table). Cashier is not mounted on Tenant because Connect already owns the Stripe client and the classic Cashier webhook path. Checkout never touches card data.

Trial is 30 days with no card (`trial_ends_at`). After expiry without a subscription, admin writes 403; public booking stays up. Failed invoices dunning-mail day 0, 3, 7 then the same read-only lock. Pause keeps diary access.

New registrations start with `booking_page_live = false` and a `preview_token`. Factories stay live so existing tests match a salon that is already open.

Marketing is Blade at the root domain (not Vue) so vertical copy such as dog grooming never lands in the admin SPA.

## Design pass (prompt 5)
Dark-first tokens in `resources/css/tokens.css`. Home after onboarding is the diary (`home_route()`). Command palette search is `GET /search` over existing customers — not a new product surface. Optimistic diary writes are local-only until the existing `bookings.store` responds.

## UI rebuild — foundation (phase 1)

The dark-and-amber system is retired. Light-only, warm paper, ink actions. See
`DESIGN.md`. Notes on what was changed away from the brief, and why:

**`--accent` darkened from `#B5612F` to `#A85729`.** The specified value
measures 4.31:1 on paper, 3.98:1 on paper-sunk and 4.45:1 on white — under 4.5
on all three surfaces. It is used as type (waitlist counts, "first available"),
so it was darkened to the nearest value clearing 4.5:1 everywhere. Verified by
`npm run check:contrast`.

**`--ink-3` reclassified from text to disabled-only.** The brief listed it as
"tertiary text, captions"; measured, it is 2.5:1 on paper and unusable as text
at any size. Captions use `--ink-2` (5.0:1). `ink-3` and `ink-4` survive for
disabled and struck-through states, which WCAG exempts. This keeps the
specified hex values and changes only their usage.

**Dark mode removed rather than disabled.** No `prefers-color-scheme` branch
remains in `tokens.css`. Reintroducing dark later means authoring a second
palette, not restoring a commented-out one.

**Density scopes kept from the previous pass.** `data-density` on a surface
root drives control height, row height, rhythm and field size, so one component
library serves the operator app, the public page and the console without any
component taking a size prop. Values were retuned to the new system.

**Status colour dropped to monochrome.** The admin app uses ink weight for
status; only a cancellation earns `--danger`. `--status-*` tokens exist so the
diary's 2px left borders have names, but they resolve to ink shades.

### Noted, not fixed

Per "note broken logic rather than fixing it":

- `Skeleton shape="row"` draws three bars regardless of the table's column
  count, so a five-column table's loading state has a visible gap. Cosmetic;
  belongs with the components phase.
- `EnsureSubscriptionWrite` still applies a tenant's billing read-only lock to
  a super admin who is not impersonating. Agreed behaviour: bypass when not
  impersonating, stay subject to it while impersonating. Queued for the super
  admin phase.

## Hostname split

Four hostnames, one app, one database, one deployment. `routes/web.php` is gone;
see `DEPLOY.md` for DNS and SSL, and the README for local setup.

**Five route files, four surfaces.** `routes/machine.php` holds the Stripe and
Twilio webhooks and `/health`. Those are not a user surface — nobody browses
them, they carry no session, and they authenticate by signature rather than by
cookie — so binding them to one hostname would only break a provider's
configured URL for no security gain. They are registered on every host. The
"no route in more than one file" rule holds: each route is declared once.

**Impersonation crosses the boundary with a signed handoff.** A cookie for
`app.` cannot be set from `admin.`, which is the point of the split. The console
issues a 60-second, single-use signed URL; the app surface verifies the
signature, pulls the nonce from the cache so it cannot be replayed, re-checks
that the actor is still a super admin, and only then opens a session tagged
`impersonator_id`. Both ends write an audit row. Exiting destroys the app
session and returns to the console, whose own session was never touched.

**The console has its own login.** A super admin authenticating on `app.` would
defeat the separation, so `admin.` has its own login form and its own cookie. A
salon owner who submits correct credentials there is logged straight back out
and gets the same message as a wrong password — the surface does not confirm
that an account exists. Password reset for super admins is not implemented on
`admin.`; there are two of us and it can be done from the database.

**`BOOKING_HOST` is retired**, superseded by `APP_URL_BOOK`.

**`XSRF-TOKEN` keeps its name on all four surfaces.** It is host-scoped like the
session cookie, so it cannot travel between them, and renaming it would mean
patching the JS that reads it for no gain.

**Per-surface middleware groups.** `surface.marketing`, `surface.app`,
`surface.book`, `surface.admin`. The rate limits and the IP allowlist live in
the group rather than on individual routes, so a route added to a surface
inherits them without anyone remembering to.

**Cross-surface links are shared as Inertia props**, not built in Vue from a
bare path. An Inertia `<Link>` cannot cross a hostname, so anything leaving a
surface is a plain `<a>` pointed at `page.props.urls.*`.

**An app session presented to the console is not directly tested.** The
guarantee is structural — the cookie is pinned to `app.{domain}` so a browser
never sends it, and the console reads a differently named cookie so it would
not look for one. Only the server half is testable: `actingAs()` bypasses
cookies, so a test that "presented" one would exercise the harness rather than
the app. A test asserting that was written and deleted rather than left to pass
for the wrong reason.

### Noted, not fixed

- `route:cache` bakes the hostnames in. Changing any `APP_URL_*` needs
  `route:clear && route:cache`, not just `config:clear`. Documented in
  `DEPLOY.md`; there is no guard that catches it.

## Rename — Kestrel → Appoint Manager

Display name **Appoint Manager**, sentence-case in prose, never `AppointManager`
and never all-caps. Machine slug `appoint-manager`; database and cache
identifiers `appoint_manager` or `appoint-manager` as the surrounding convention
requires.

Most of the product never carried the name in the first place: `config/app.php`
reads `APP_NAME`, and the cache prefix, Redis prefix, session cookie name, mail
from-name and every mail signature derive from it through `Str::slug()`. Setting
`APP_NAME="Appoint Manager"` moves all of them at once —
`Str::slug('Appoint Manager', '_')` is `appoint_manager` and
`Str::slug('Appoint Manager')` is `appoint-manager`, which is exactly the
convention each of those call sites wanted.

Decisions taken rather than asked about:

**`DB_DATABASE` becomes `appoint_manager` in `.env.example` only.** The local
`.env` was not touched. To move an existing database:

```sql
-- MySQL has no RENAME DATABASE. Create, move every table, drop the old one.
CREATE DATABASE appoint_manager
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- For each table (or generate this from information_schema):
RENAME TABLE kestrel.<table> TO appoint_manager.<table>;

DROP DATABASE kestrel;
```

Recreating instead is a one-liner and is the right move locally:

```sql
CREATE DATABASE appoint_manager
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
```bash
# then, with DB_DATABASE=appoint_manager in .env
php artisan migrate:fresh --seed
```

**`HORIZON_PREFIX` becomes `appoint-manager-horizon`.** Horizon namespaces
queues, job payloads and its own metrics under this prefix, so **jobs queued
under `kestrel-horizon` are orphaned the moment the prefix changes** — nothing
reads them, and they are not retried or dead-lettered, they are simply invisible.
This must land on an empty queue: stop the dispatchers, let Horizon drain, then
deploy. The same applies to `CACHE_PREFIX` and `REDIS_PREFIX`, which move with
`APP_NAME` — cached values are not lost, they are stranded, and the first
request after deploy recomputes everything. That is a cold-cache spike, not a
correctness problem.

**Hostnames become `appoint-manager.com` / `appoint-manager.test`.** A hostname
is a machine identifier, so it takes the machine slug rather than the display
name. Only `DEPLOY.md`, `README.md` and the surface routing test carried a
literal hostname; the app reads them all from `APP_URL_*`.

**The mark is unchanged.** It is a square severed on the 1:2 diagonal, and it
was originally justified as a kestrel's stoop angle. The geometry earns its
place without the bird: the cut falls twice as far as it runs, so the two halves
are the same shape at different weights — one primitive, one cut, legible at
16px. The comments in `mark.svg`, `mark-mono.svg` and `DESIGN.md` were rewritten
to describe the shape. No path data changed.

**Brand sentences were rewritten, not substituted.** `resources/css/tokens.css`
said "Kestrel's own accent" and now says "the product accent";
`DESIGN.md` said "Kestrel's own terracotta" and now says "the product's own
terracotta". The auth right-hand panel that the brief specifies does not exist
in the code yet, so there was no line there to rewrite — it lands with the auth
phase, and it needs to be written as a sentence about what Appoint Manager does,
not the old sentence with a different noun in it.

**`__kestrelCard` / `__kestrelStripe`** — the window globals the Stripe islands
park a card element on — became `__appointManagerCard` / `__appointManagerStripe`.

**`package.json` gained `"name": "appoint-manager"`.** It had none, so npm was
naming the lockfile after the working directory, which is still `Kestrel`.

### Left alone, deliberately

- **`composer.json` is still `laravel/laravel`.** It never carried the old name,
  so it was out of the rename's scope. Worth changing on its own at some point.
- **The working directory is still `~/Projects/Kestrel`** and the git remote, if
  any, is untouched. Renaming a checkout is the user's call, not a code change.
- **`site.webmanifest` `short_name` is `Appoint Manager`**, 15 characters. The
  manifest spec suggests ~12 for `short_name` and some launchers will truncate.
  Inventing a shorter second name ("Appoint") would be inventing brand, so it
  keeps the full name and gets truncated rather than renamed.
- ~~**There are two identical manifests.**~~ `public/icons/site.webmanifest`
  was deleted; `public/site.webmanifest` is the only one, and it is the one
  `partials/head.blade.php` links.

## Auth verification (no rewrite)

Every flow below was walked against the real routes and controllers on
2026-08-25, via a temporary Pest file that was deleted afterwards. **No
cross-tenant read and no auth bypass was found.** Four cross-tenant probes —
read a customer, read a booking, PATCH a service, DELETE a customer, all by
another tenant's id — returned 404 with the row untouched and no name in the
body. An unauthenticated admin route and a super admin route hit by a tenant
owner both fail closed (302 to a login, 403).

Logout is POST-only (`GET /logout` is 405), invalidates the session, rotates the
session id and the CSRF token, and a subsequent `GET /diary` redirects to the
login. The copy is already sentence-case "Log out" in both places it appears
(`AppLayout.vue:235`, `Pages/Auth/VerifyEmail.vue:36`) — there was no `Log Out`
to fix, so no string was changed and this section touched no code at all.

### Noted, not fixed

- **`Cache-Control` on authenticated pages is `no-cache, private`, not
  `no-store`.** A real back-navigation revalidates and lands on the login, so
  the server side is correct. But `no-cache` does not disable the browser's
  back/forward cache, so after logging out the back button can still *paint* the
  last authenticated screen from bfcache until something on it makes a request.
  Framework default, one header to change, but it is a behaviour change on every
  response — error-states phase.
- **A guest hitting an admin route under path-fallback routing is sent to the
  app login, not the console login.** `bootstrap/app.php:61` picks the login by
  `Surface::fromHost()`, and `Surface::fromHost()` returns `Surface::App`
  unconditionally when `subdomain_routing` is off (`app/Support/Surface.php:93`).
  So locally `GET /admin` redirects to `/login` rather than `/admin/login`. It
  still fails closed — the console needs a super admin either way — and it is
  correct in production, where subdomain routing is on. Only local and CI see it.
- **The console has no logout control.** `POST /admin/logout` exists
  (`routes/admin.php:28`) and works, but nothing in `Pages/SuperAdmin/*.vue`
  renders a button for it. Super admin phase.
- **419 on a stale session is the Laravel default error page.** There is no
  `$exceptions->render()` for `TokenMismatchException` in `bootstrap/app.php`,
  so a stale CSRF token drops the operator out of the product's visual language
  entirely. Noted only — error-states phase, per instruction.
- **`EnsureSubscriptionWrite` still applies a tenant's billing lock to a super
  admin who is not impersonating** — already recorded above, re-confirmed by
  this walk, still queued for the super admin phase.

## UI rebuild — foundation, second pass

`tokens.css` is now the single source in fact and not only in intent.

**`--brand` and `--brand-fg` now exist.** `tailwind.config.js:51-52` mapped
`bg-brand` and `text-brand-fg` to `var(--brand, var(--ink))` — a variable that
was never defined anywhere, so `bg-brand` silently resolved to ink and
`AppLogo tone="brand"` was dead code that looked alive. Both are defined in
`tokens.css`, `--brand` defaulting to ink, and the Tailwind mapping dropped its
inline fallback so there is exactly one place the default lives.

**The six brand presets moved out of `check-contrast.mjs` into `tokens.css`.**
They were hardcoded in the checker at lines 67-74, which meant the six values
being contrast-checked were a copy, and nothing that actually shipped was ever
measured. `check:contrast` now parses `--brand-*` out of `tokens.css` and fails
if it does not find exactly six. Verified by putting a neon yellow in
`--brand-ochre` and watching the check fail at 1.53:1.

**Named tokens for what the mockups hardcoded.** `--badge-h`, `--skeleton-h`,
the six `--col-*` widths, `--sub-indent` and `--booking-w`, all wired into
`tailwind.config.js` and all substituted back into the mockups so the two cannot
drift. Every value is a multiple of 4.

- `--badge-h` stays at **20px**. The instruction was to land it on the 4px
  scale; it already is (5 × 4). It is off the *space* scale (4/8/12/16/24/…),
  which is where the "off it" reading comes from, but a badge is chrome, not
  spacing — the same distinction that lets `--row-h` be 34 and `--rail` 148.
  16px clips a 12px label and 24px makes a status read as a button.
- `--skeleton-h` was **10px in the mockup, which is off the grid**, and is now
  **8px** — the nearest grid value that still reads as a line of text.
- `--col-when` was **150 → 152** and `--col-amount` **110 → 112**. The other
  four column widths were already multiples of 4.
- `--sub-indent` is `calc(var(--col-time) + var(--space-4))` rather than the
  mockup's literal `72px`, so the sub-line cannot drift off the column it hangs
  from.
- `--booking-w` replaces a hardcoded `440px` that was sitting in
  `tailwind.config.js` rather than in `tokens.css`.

**Tracking at 34 and 24 was loosened.** `-0.035em` at 34 was chosen while the
mockups were rendering in the system face, because Geist was named in
`--font-sans` but no stylesheet ever requested it. Re-measured in Chrome with
Geist actually loading: Geist is a tightly fitted grotesque already, and at 34px
`-0.035em` closes the counters and pulls punctuation into the digit next to it
("£1,248 · 34"). `--tracking-34` is now `-0.03em`. `--tracking-24` moved to
`-0.025em` with it, so the ladder still tightens with size instead of flattening
at the top — that second change was not asked for and is a one-line revert.

**All three faces are now loaded.** `resources/views/partials/head.blade.php`
requested Geist and Geist Mono at 400/500 but never Inter, which
`--font-sans` names as its fallback. A named-but-unloaded fallback does nothing:
if Geist fails, the stack drops straight to the system face. Inter 400/500 was
added to the same `fonts.bunny.net` request, which the CSP already allows. The
three mockups requested no font stylesheet at all, which is why they rendered in
the system face; they now link the same one.

**`check:design` scans everything under `resources/`**, not `resources/{js,views}`.
The premise that Blade partials were unguarded turned out to be wrong — `**`
matches zero segments, so `resources/views/partials/head.blade.php` was already
covered. The real gap was the stylesheets and the SVG marks, six files, and they
are the two places a raw hex is most likely to be typed. Three changes came with
that:

- Rules are scoped. The existing rules describe Tailwind *class names*, and
  running them over a stylesheet only produces noise —
  `box-shadow: var(--focus-ring)` is the correct way to write the one shadow
  this system has. CSS gets its own rules: raw hex, a `box-shadow` that is not
  the focus ring, a raw `rgb()`/`hsl()`/`oklch()`, and `text-transform:
  uppercase`.
- Comments are stripped before matching. Prose explaining why there is one
  shadow should not read as a second shadow.
- `tokens.css` is the one exempt file — it is where the raw values are supposed
  to live.

**A drift check for values that cannot hold a variable.** The `theme-color`
meta and the two manifests' `theme_color` / `background_color` must restate
`--paper` as a literal, because both are read before any stylesheet is. They are
now compared against `tokens.css` on every run, so they can restate it but
cannot drift from it. Verified by changing one to `#FFFFFF` and watching the
check fail.

**One hardcoded colour was removed:** `resources/js/app.ts:28` fell back to
`'#D9A441'` if `--accent` did not resolve — an amber from the retired dark
system, a colour that is no longer anywhere in the product. The progress bar is
injected into the document and can resolve the variable itself, so the literal
is gone and the value is `var(--accent)`.

That is the only one. `resources/` was otherwise already clean of raw colour and
of Tailwind default-palette classes; `bg-white` and `text-white` are real tokens
here (`--white`, for inputs and unselected slots), not the Tailwind default.
Two literals remain and are explicit, counted opt-outs:

- `resources/js/lib/staffColour.ts:10` — `#0F766E`, the default staff colour.
  Staff colours are per-user *data*, not tokens; `DESIGN.md` already calls them
  the one legitimate colour outside this system.
- `resources/views/partials/head.blade.php:16` — the `theme-color` meta, now
  guarded by the drift check above.

### Noted, not fixed

- **`--pad-x` is off the 4px grid** — 10px compact, 14px roomy, 8px console.
  Inherited from the density pass. Moving it changes the horizontal padding of
  every control in the product, which is a components-phase decision, not a
  token-file one.
- **`::selection` disagrees between the mockups and the code.** The mockups use
  `background: var(--ink); color: var(--white)`; `resources/css/base.css:57`
  uses `--accent-tint` behind `--ink`. Tokens do not settle this one — both are
  built from tokens. The code's version spends the accent on something that is
  not one of the three meanings the accent is rationed for, so it is probably
  the wrong one, but changing it is a visual decision.
- **`maxWidth`, `minWidth` and the `w-*`/`max-w-*` size scales are still
  Tailwind defaults.** `colors`, `fontSize`, `borderRadius` and `boxShadow` are
  replaced so off-token values compile to nothing, but the width scales are
  only extended — `max-w-sm`, `min-w-44` and friends still work, and several
  screens use them. Not a colour problem, so it is outside this phase's remit,
  but it is a hole in "every value comes from `tokens.css`".
- **`Skeleton shape="row"` still draws three bars regardless of column count** —
  already recorded above. The `--col-*` tokens now exist specifically so the
  components phase can fix it properly.

## Local URL resolution

Two separate bugs, one visible and one worse.

**`APP_URL` had no port.** Every route and cross-surface URL is generated from
it, so with `APP_URL=http://localhost` and the server on `127.0.0.1:8000` the
pages rendered but every generated link pointed at port 80. Reproduced against
the running server: `props.urls.marketing` came back as `http://localhost`.
Nothing infers the port from the request, and nothing should — a generated URL
has to be right in a queued job and a mail template too, where there is no
request. `.env.example` now ships `APP_URL=http://127.0.0.1:8000`.

**A blank env value is a value.** `env()` returns `''` for a key that is present
and empty, and falls back to its default only for a key that is absent. Every
surface variable in `.env.example` shipped present-and-blank, so
`env('APP_URL_APP', env('APP_URL'))` returned `''` rather than `APP_URL`, and
`Surface::url()` returned an empty string. Measured on a copy of `.env.example`:

```
marketing_url() = ''          app_url('diary')  = '/diary'
admin_url()     = '/admin'    book_url(null,'x') = '/book/x'
```

So the wordmark on the login page linked to `href=""` — a link back to the page
you are already on. Anyone starting from `.env.example` got that on day one.

Two fixes, because either alone leaves the trap set:

- `config/app.php` treats blank as unset for `APP_DOMAIN`, `SUBDOMAIN_ROUTING`
  and the four `APP_URL_*`. A local closure, so the cached config array stays
  serialisable.
- `.env.example` comments those six out rather than leaving them blank, with
  the real production values shown in the comment.

The old file was one deleted line away from something worse: with `APP_DOMAIN=`
blank and the `SUBDOMAIN_ROUTING=` line removed, `env('APP_DOMAIN') !== null` is
`'' !== null`, which is **true** — subdomain routing would have switched itself
on against four hostnames that do not exist. It only ever worked because the
blank `SUBDOMAIN_ROUTING=` line happened to sit next to it.

Verified after the fix, against a real server: `/`, `/pricing` and `/login`
return 200 unauthenticated, `/diary` redirects to the login, a real POST to
`/login` lands on `/diary`, and `/diary`, `/dashboard`, `/bookings`,
`/customers`, `/services`, `/settings` and `/profile` all return 200
authenticated. Nothing in the rename touched host resolution: the diffs to
`config/app.php` and `app/Support/Surface.php` are comments, plus the
`config('app.name')` *fallback* used to derive the session cookie name.

**Changing `APP_NAME` orphans the current local session cookie.** The cookie is
`Str::slug(config('app.name'), '_').'_app_session'`, so it becomes
`appoint_manager_app_session` and the browser's `kestrel_app_session` is
ignored. One re-login, nothing else.

## Phase 1 loose ends, closed

- **The duplicate manifest is gone.** `public/icons/site.webmanifest` deleted;
  `public/site.webmanifest` is the only one and is the one that is linked.
- **`--pad-x` is on the 4px grid**: 10/14/8 became **12/16/8**. Monotonic across
  the three densities, and 2px a side wider on a compact control. Done now
  rather than after phase 2, because every control the components phase builds
  inherits it.
- **`::selection` is ink on white.** It was `--accent-tint` in `base.css` and
  ink in the mockups. Selection fires on every screen at once, which is the
  exact opposite of at most one accent per screen, and "you dragged over some
  words" is not one of the three meanings the accent is rationed for. The rule
  is now written down in `DESIGN.md` so it does not drift back.

  Two more unsanctioned accent uses went with it: the action link in
  `EmptyState` and in `Toaster` (both ink now), the `Checkbox` tick (ink —
  selected states are ink everywhere), and the required marker in `Label` (ink —
  a form with six required fields would otherwise spend the accent six times
  before anything happened). `Badge tone="accent"` moved off `--accent-tint`
  onto white: accent type on accent-tint measures 4.50:1, which is not a margin.

- **The mockups' token block is gated, not generated.** `check:design` now
  parses `:root` and both `[data-density]` blocks out of each mockup and
  compares every declaration against `tokens.css`, reporting missing, extra and
  differing values.

  Gated rather than generated because a mockup's whole value is that you open it
  from disk in a browser with no build step; generating the block would put a
  script between the file and the browser. And a generated file that is
  committed drifts the moment somebody hand-edits it anyway — the gate catches
  that case, generation does not. Verified by setting `--badge-h:22px` in one
  mockup and `--danger` to a different red in another; both were reported.

- **Pint is in the gate and the 25 files are fixed.** `npm run check` now ends
  with `check:php` (`pint --test`). A gate everyone knows is red is worse than
  no gate, so the choice was to make it green rather than to document it as
  broken. The 25 files were all `ordered_imports` / `fully_qualified_strict_types`
  and similar; no behaviour changed and the suite is green either side of it.

- **`maxWidth`, `minWidth` and the `w-*` scales are still Tailwind defaults.**
  Deliberately not fixed: it is a size problem, not a colour problem, and every
  screen using one is a screen the components phase rewrites anyway. Scope, in
  full, so it can be closed in one pass:

  | Class | Where |
  |---|---|
  | `max-w-xl` | `Profile/Edit.vue` (×3), `Settings/Index.vue`, `Settings/Payments.vue`, `Billing/Index.vue` |
  | `max-w-sm` | `Layouts/GuestLayout.vue`, `Admin/Login.vue`, `ui/Modal.vue`, `ui/ConfirmDialog.vue` |
  | `max-w-md` | `ui/Modal.vue`, `ui/SlideOver.vue` |
  | `max-w-lg` | `ui/Modal.vue`, `Bookings/Show.vue`, `CommandPalette.vue` |
  | `max-w-4xl` | `Layouts/OnboardingLayout.vue` |
  | `max-w-6xl` | `Dev/Components.vue` |
  | `min-w-44` | `ui/Menu.vue` |
  | `min-w-[380px]` | `Bookings/Index.vue`, `Customers/Index.vue`, `Diary/Index.vue` |
  | `min-w-[1.25rem]` | `ui/KeyHint.vue` |

  `max-w-measure` and `max-w-booking` are real tokens and are fine.

- **The `AppLogo` wordmark: rendered, and the earlier worry was misplaced.**
  `AppLogo` is not used in `AppLayout` at all — the operator rail shows the
  *tenant's* name. It appears in exactly one product screen, `Admin/Login.vue`,
  centred at `size=20`, where "Appoint Manager" fits on one line and looks
  right.

  Rendered in a 148px rail for completeness: at `size=20` and at `size=16` the
  wordmark **wraps to two lines**, and the mark then centres against a two-line
  block instead of sitting at cap height, which breaks the lockup. At `size=13`
  — the size the mockup's rail wordmark uses — it fits on one line. Mark-only in
  the 56px collapsed rail is fine at any size.

  Not decided here, because nothing is broken today. If the wordmark ever goes
  in the rail, the options are: mark-only in the rail (the mockup already does
  this in the collapsed state), `size=13` for the lockup wherever the rail is
  the container, or a two-line lockup with the mark aligned to the first line.

### Found while verifying, and fixed

**The operator app's nav rail had no width.** `AppLayout.vue` used
`w-sidebar`, `w-sidebar-collapsed`, `pl-sidebar`, `pl-sidebar-collapsed` and
`h-topbar` against a Tailwind config that only ever defined `rail` and
`rail-collapsed`, and no `topbar` at all. Every one of those classes compiled to
nothing — confirmed by scanning the built CSS, where none of them appear. The
rail was sized by its content and the top bar by whatever was in it.

This is exactly the failure `check:design` exists to catch and could not: it
knew a list of *forbidden* classes, and had no idea what an *undefined* one
looked like. So the classes were renamed to `rail`, `--topbar: 56px` was added,
and a new rule reads the valid names out of `tailwind.config.js` itself and
fails on any `w-`/`h-`/`min-*`/`max-*`/`p*-` utility naming a token that is not
there. Two false positives were designed out of it on the way: `min-h-tap`
matching as `h-tap` (fixed with a leading guard), and Tailwind deriving width,
height and padding from `spacing` (the rule unions that scale in).

Verified after the fix: `.w-rail`, `.md\:w-rail`, `.md\:w-rail-collapsed`,
`.h-topbar`, `.md\:pl-rail` and `.md\:pl-rail-collapsed` all appear in the
built stylesheet.

## UI rebuild — components (phase 2)

**37 components in, 33 out.** Five deleted, one built.

Deleted, because nothing outside the gallery used them and none is in the set
the brief names. All five are one `git revert` away if the phase that wants
them disagrees:

| Deleted | Why |
|---|---|
| `Pagination` | No controller calls `paginate()`. Nothing in the product is paginated. |
| `Countdown` | The offer page has an expiry but does not render a countdown; phase 4 decides whether it should. |
| `Duration` | Nothing uses it. `Services/Index.vue` prints `{{ duration_minutes }} min` inline and is a phase 5 screen. |
| `DatePicker` | Nothing uses it. The diary uses native date inputs; phase 5 owns that decision. |
| `TimePicker` | As above. |

Built: **`UserMenu`**, the one component in the brief's list that did not exist.
`AppLayout` had it hand-rolled — a bare `v-if` panel with no `aria-expanded`, no
Escape, no outside-click, no focus return and no arrow keys. It is a menu, so it
behaves like one, and `AppLayout` now uses it.

Kept and genuinely used rather than deleted: `Field`, `FieldError`, `Label` and
`Spinner` are used *inside* other components (a relative import, which the first
usage scan missed); `Callout` by `Auth/VerifyEmail.vue`; `WeeklyHoursGrid` by
two real screens. `Menu`, `MenuItem` and `KeyHint` were gallery-only and are now
adopted — `Menu` by `Table`'s row actions, `KeyHint` by the top bar's `⌘K`.
`Card`, `Stat` and `EmptyState` are adopted by `Dashboard.vue`, which was
hand-rolling all three and was never on the pending list.

**Eight components are still gallery-only** — `Badge`, `Checkbox`, `Combobox`,
`MenuItem`, `Table`, `Tabs`, `Textarea`, `Toggle`. They exist, they are built to
spec and they are on `/dev/components`, but the screens that will consume them
are the twenty on the pending list, and rewriting those is phase 5. Adopting a
control into a queued screen means rewriting that screen, so the honest number
is eight, not zero. (`Skeleton` came off that list: `Table` uses it. `MenuItem`
is on it because `Table` takes it through a slot from the caller, and the only
caller so far is the gallery.)

### Table

Sortable with `aria-sort` on every sortable column (`none` when unsorted, which
was missing), sticky header, hairline rows, no zebra, numbers right and mono,
`EmptyState` built in, and row actions in a `Menu` rather than a slot each
screen fills with five links.

Column widths are **token names** — `when`, `time`, `staff`, `status`, `amount`,
`actions` — not arbitrary Tailwind classes. The prop used to be
`width?: string`, which is a hole the size of the problem it was meant to close.

### Skeleton, and the two bugs the gallery found

`shape="row"` drew three bars regardless of column count, so a five-column table
loaded with a gap and then snapped into a different shape. It now takes the
columns and draws one bar per column; `Table` composes single bars into its own
`<tr>`/`<td>` so the loading state inherits the real column widths exactly.

Bar widths are **fractions of their column**, not named pixels. The mockups
carried ten ad-hoc bar widths (40/56/80/88/104/112/120/150/168/188) that nothing
guarded; a fraction follows whatever the column is set to and cannot drift. The
ragged edge is index-derived, not random, so a table always draws the same
skeleton and nothing re-flows between frames.

Two bugs only visible once it was rendered, both found on `/dev/components` and
neither catchable by any of the three gates:

1. **The bars were `--paper-sunk` on a white table** — a 1.06:1 difference, i.e.
   invisible. The loading table rendered as four empty rows. They are `--ink-4`
   now, which is the token for a disabled fill and what the mockups used.
2. **The bar had no width.** `shape="bar"` sizes itself as a percentage of its
   cell, but its root element sized to its content, so the percentage resolved
   against zero. Fixed with `w-full` on the root for that shape.

That is the argument for the gallery being the deliverable: both of these pass
type checking, pass all three gates, and are obvious the moment a browser draws
them.

### Focus, keyboard and motion

`Modal`, `SlideOver` and `ConfirmDialog` already shared `useFocusTrap`, which
traps Tab, handles Escape, locks body scroll and restores focus to the trigger.
`Menu`, `UserMenu` and `Tabs` were brought up to the same standard: arrows move,
Home and End jump to the ends, Escape closes and restores focus, Tab closes
without stealing the focus move, and arrows on a closed trigger open the menu
onto its first item. `Tabs` uses a roving tabindex, so a tablist costs one Tab
press to get past rather than one per tab.

Motion is unchanged and already correct: 120ms state, 180ms entrance, opacity
and border-colour only, `prefers-reduced-motion` instant.

### `border-accent` / `border-rule` set all four sides

Six places in the library paired a side-scoped border *width* with an unscoped
border *colour*. None was visibly wrong — only one side had width — but the
pattern is one added border away from a bug. `SlideOver` (×3), `Modal` (×2),
`CommandPalette`, `Stat` and `Tabs` now use `border-b-rule`, `border-l-ink` and
so on, and `Table` was written that way from the start.

### `check:components`

- The **hard rule** is enforced: no page component contains a hand-rolled input,
  button, table, modal or menu. A `menu` rule was added (`role="menu"`,
  `aria-haspopup`) because a hand-rolled dropdown is the one that always ships
  without Escape, without outside-click and without focus return.
- **The gallery is no longer exempt.** It used to be skipped wholesale; it now
  passes the same rule as every other screen, which is the least it can do given
  what it is for.
- **`MAX_PENDING = 20`** is a ceiling. The list already errored when a file on
  it had gone clean; it now also errors if the list grows. Both directions are
  covered, so "the list only shrinks" is enforced rather than requested.
- Verified by putting a raw `<button>` into `Dashboard.vue` and watching the
  check fail with a non-zero exit.

The allow-list, unchanged at 20 entries, each with the phase that clears it:

| Screen | Cleared by |
|---|---|
| `Pages/Public/BookingIsland.vue` | phase 4 — public booking |
| `Pages/Public/ManageIsland.vue` | phase 4 — public booking |
| `Pages/Public/OfferIsland.vue` | phase 4 — public booking |
| `Pages/Diary/Index.vue` | phase 5 — operator app |
| `Pages/Bookings/Index.vue` | phase 5 — operator app |
| `Pages/Customers/Index.vue` | phase 5 — operator app |
| `Pages/Services/Index.vue` | phase 5 — operator app |
| `Pages/Staff/Index.vue` | phase 5 — operator app |
| `Pages/Availability/Index.vue` | phase 5 — operator app |
| `Pages/TimeOff/Index.vue` | phase 5 — operator app |
| `Pages/Waitlist/Index.vue` | phase 5 — operator app |
| `Pages/Settings/Index.vue` | phase 5 — operator app |
| `Pages/Billing/Index.vue` | phase 5 — operator app |
| `Pages/Imports/Index.vue` | phase 5 — operator app |
| `Layouts/AppLayout.vue` | phase 5 — operator app |
| `Components/WeeklyHoursGrid.vue` | phase 5 — operator app |
| `Pages/Onboarding/Index.vue` | phase 6 — onboarding |
| `Pages/Auth/Login.vue` | phase 6 — auth |
| `Pages/SuperAdmin/Index.vue` | phase 7 — super admin |
| `Components/CommandPalette.vue` | phase 10 — command palette |

### Noted, not fixed

- ~~**`check:components` does not read Blade.**~~ **Closed.** The scanner now
  reads `resources/views/**` as well as the Vue tree. Marketing does not use
  `<button>` or `<input>` at all — it copies `Button.vue`'s class list onto an
  `<a>` — so a `copied-control` rule was added to catch a control that is not
  wearing a control's tag. `marketing/partials/cta.blade.php` is allow-listed
  for **phase 11**, not phase 9.
- **The overlay components take `show` + `@close`, not `v-model:open`.**
  `v-model:open` is the better API. Changing it means touching six consumers,
  five of which are on the pending list and get rewritten anyway, so it should
  happen as those screens land rather than as churn now.
- **The mockups' mark is not `mark.svg`.** `public/mockups/dashboard.html` draws
  a different two-path shape with an opacity-0.3 half. The real mark is the 1:2
  severed square. Cosmetic, in a reference artefact, but they should agree.


## The mockups are binding (phases 6 and 7)

`public/mockups/dashboard.html` and `public/mockups/bookings-table.html` are the
**target**, not a mood board. They were approved, and phases 6 and 7 match them.

Previous phases treated them as directional and the admin shell drifted a long
way from them. That is the thing this entry exists to stop.

What "match" means, concretely — these are the things to check off, and they are
already expressed in tokens rather than in numbers typed twice:

| Element | Mockup | Token |
|---|---|---|
| Nav rail | 148px, on paper-sunk | `--rail: 148px`, `bg-paper-sunk` |
| Rail, collapsed | 56px | `--rail-collapsed: 56px` |
| User control | pinned to the **bottom** of the rail | `position: absolute` in a `relative` rail foot |
| Weighted band | the figure band under the header | `.band`, `bg-paper-sunk` card |
| Timeline | includes the **freed-slot row** | accent left border, `border-l-2 border-l-accent` |
| Salon mark | square initial | `.initial-square` |

**Deviate only where a token or an accessibility rule forces it, and say so in
that phase's report.** "It looked better this way" is not one of those reasons.
If a mockup value is off the 4px grid or off the type scale, the token wins and
the deviation gets named — that has already happened once for `--col-when`
(150 → 152) and `--skeleton-h` (10 → 8), both recorded above.

Known disagreement, still open: the mockups draw a different mark from
`mark.svg` (see "Noted, not fixed"). The real mark wins; the mockup is wrong.

## Phase 3 — per-tenant accent

- `brand_colour` on `tenants`, nullable, storing a **preset name**, never a hex.
- The six presets live in `resources/css/tokens.css`. `App\Support\BrandPalette`
  reads the *names* out of that file and PHP never learns the colours: a choice
  reaches the browser as `--brand: var(--brand-forest)`. That is what keeps the
  stylesheet the only file that knows what forest looks like.
- No free hex field, ever. Six presets that each clear 4.5:1 against white
  button text is a promise we can keep; a colour picker is a promise that
  somebody ships neon on white and *our* product looks broken.
- Two places on the booking page: the salon's initial mark and the primary
  button. Slots stay ink — a time is a choice the customer makes, not a thing
  the salon brands.
- The operator app stays monochrome. The one exception is the preview on
  Settings → Branding, which is `inert` scenery, and a test enforces that
  nothing else in `Pages/` or `Layouts/` paints with the brand.

---

# Phases 4–7

Numbering follows the one in use since 2026-08-25: 4 the suggester, 5 public
booking, 6 the admin shell, 7 the admin screens, 8 auth, 9 super admin,
11 marketing.

## The three fixes that came first

**The freed slot was invisible.** `DiaryController` and `DashboardController`
both carried `where('status', '!=', 'cancelled')`, which hid the one row on
either screen that is worth acting on. `App\Services\Booking\FreedSlots` decides
which cancellations are real gaps; both queries now load cancelled rows and ask
it. The filter could not simply be deleted, because a cancellation whose hour
has been refilled is not a gap.

*Refilled is measured, not asserted.* The first version treated any overlap as a
refill, and the demo tenant caught it immediately: Marek's 15:30–17:00
cancellation overlaps his own 16:30 appointment by thirty minutes, so the freed
slot with three people waiting for it vanished from both screens. Live bookings
are subtracted from the cancelled window and the largest surviving stretch is
what gets reported — with its own start, because a cancellation whose first half
hour has been rebooked frees an hour that begins late. Under fifteen minutes
there is nothing to sell and the row is drawn as history.

**Logout painted the marketing page inside the app.** `redirect('/')` from an
Inertia visit is followed by the client, which then receives a Blade document it
has no page component for and paints it as a partial. `Inertia::location()`
answers 409 with `X-Inertia-Location`, which the client turns into a real page
load. `ProfileController::destroy` had the identical bug and is fixed with it.
The test asserts the *response type* — 409 plus the header — because the broken
version was a perfectly healthy 302 → 200 and a status assertion could not see
it.

**`composer.json`** is `appoint-manager/appoint-manager`.

## Phase 4 — AppointmentSuggester

The ranking rule and its explanation are the same code. `ReasonKey` is the
complete list of things a proposal may say about itself, in the order the
primary prefers them, and a slot matching none of them is not proposed at all.
That constraint is what stops this drifting into "some slot, with a caption".

Decisions taken while building it, all of them forced by the demo tenant:

- **A sub-weekly median gap is not a rhythm.** Plenty of demo clients measured a
  one-day interval, because they have two dogs and bring them on consecutive
  days. Proposing "you are due tomorrow" off that is exactly the confidently
  wrong claim the reason line exists to prevent, so under seven days the
  customer's own interval is discarded and the service's is used.
- **One visit is not a habit.** A weekday or a time-of-day claim needs at least
  two previous bookings, and a weekday needs a strict majority — two of three.
  A single visit gets `due_now`, which is the strongest thing that is still true.
- **`usual_time` says "around your usual time"** and names no number. It was
  "Your usual time, 12:30" for somebody who always comes at 14:00 — a sentence
  that contradicts itself in the same breath. Tolerance dropped from 90 to 60
  minutes with it.
- **Duplicate alternative labels are disambiguated by date.** A Saturday-only
  salon produced "Saturday morning" twice, which is one alternative shown twice.
- **`services.suggested_interval_days`** is new, nullable. A nail clip comes
  round every three weeks and a double-coat groom every ten; one global number
  is wrong for both. Null falls back to `config('booking.default_interval_days')`.

**The suggester must not depend on `TenantContext`.** Its eager loads carry
`withoutGlobalScopes()` of their own — `withoutGlobalScopes()` on an outer query
does not reach relations, and the public booking host is unauthenticated and has
no context. Without it, a returning customer was silently handed the salon's
*first* service instead of their own. Found by a demo-tenant test.

**`DemoDataSeeder` now runs in `testing` as well as `local`.** Not a loophole:
the suggester and the dashboard have to be judged against a real week, and a
four-booking fixture cannot tell you whether "your usual Tuesday" is true of
anybody. The test database is `:memory:` and `RefreshDatabase` throws it away
between tests, so there is nothing to delete that the same test did not create.
`php artisan demo:seed` stays local-only.

## Phase 5 — public booking

**The calendar is gone.** `AppointmentSuggester` decides one finished
appointment and three spread ways out; the picker is behind `Pick another day`,
the quietest control on the page.

Deviations from `booking-proposal.html`, and why:

- **The 34px line breaks before "at", not after it.** The mockup ends line one
  with "at". At 375px that only works for short dates — "Wednesday 26 August at"
  is 22 characters, overflows 343px of column, and wrapped "at" onto a line of
  its own. The alternatives were dropping below the type scale or abbreviating
  the month.
- **The mark is the salon's initial, not the mockup's geometric mark, at 20px
  rather than 16.** Putting *our* logo on a customer-facing shopfront is the one
  place the product must not appear, and DESIGN.md says the same. 20px because a
  letter inside a 16px square stops being a letter.
- **The header carries the salon name only.** The town moved to the title, the
  meta description and the JSON-LD, which is where it earns something.
- **`Earlier` / `Later` on the week rail.** The mockup draws one week and stops,
  which reaches seven days. They are `Button variant="ghost"`, the quietest
  control the library has.
- **The service switcher lives inside the picker**, not beside the proposal. The
  proposal view is the mockup's nine elements and a tenth would be a tenth; a
  customer who has opened the picker is browsing, and that is where browsing
  belongs.
- **The context line leads with the reason.** The mockup leads with the service.
  This is the whole point of phase 4 and it is one line either way.

**Recognition is by the manage-link cookie or `?ref=`, never by email address.**
`App\Support\ReturningCustomer` holds the rule, including the check that stops
one salon's cookie identifying a visitor at another salon on the same hostname.
The token in the cookie is the same capability the confirmation email already
handed over; `HttpOnly`, `SameSite=Lax`, host-only.

**`AvailabilityEngine::gridFor()`** is new: every start the salon *could* take,
ignoring who is booked. The picker needs two answers — what the day is, and what
is left of it — because an empty grid reads as a broken page and a grid with
three times in it cannot say whether the salon is busy or shut.

**Lighthouse, mobile, `/book/paw` on a local production build**, five runs:
performance **94–98** (95, 95, 96, 94, 98), accessibility **100** every run,
best practices 100, SEO 100. FCP 1.8–2.3 s, LCP 2.3–2.8 s, TBT 0 ms, CLS 0.

The spread is the server, not the page: `php artisan serve` is a single-threaded
PHP process and it is the whole of the variance — TBT is zero and CLS is zero on
every run, so nothing the browser does after the document arrives moves. The
honest reading is "95+ on a good run, 94 on a bad one, against a dev server".

`label-content-name-mismatch` failed on the first run: the alternative rows
carried an `aria-label` that reworded the visible text, so a speech-input user
saying "Wednesday morning" activated nothing (WCAG 2.5.3, Label in Name). The
label was removed — the visible text *is* the accessible name.

## Phase 6 — the admin shell

`dashboard.html`, matched: 148px rail on `--paper-sunk`, hairline right border,
13px nav with the active item on `--ink-tint` and counts right-aligned in mono,
the user control pinned to the bottom opening *upward* with Profile, Billing, a
hairline and `Log out` in `--danger`.

**The wordmark.** "Appoint Manager" is fifteen characters; inside a 148px rail
with `px-4`, a 20px mark and an 8px gap there are 88px left, and 13px sets it at
about 96px — it truncated to "Appoint Manage…". Three options: mark only, a
smaller wordmark, or two lines. **Two lines.** Mark-only loses the name at the
width with the most room for it; 12px would make the product's own name the
smallest text on screen and puts the type scale to work it is not for. A stacked
lockup is a normal thing for a mark to do; a shrunken one is an apology.

**The tablet rail, rendered for the first time, was broken.** Deriving the
56px glyph from the first letter gives Services, Staff and Settings all "S" —
three items the rail cannot tell apart in the one mode where the label is gone.
Glyphs are chosen per item now (`Sv`, `St`, `Se`), two letters, no collisions.
Letters rather than icons because this product has no icon set and eleven
invented pictograms would be eleven guesses about what "Time off" looks like.

**The active nav item has never highlighted.** `route()` returns an absolute URL
and `page.url` is a path, so the comparison was false on every screen. Both
sides are reduced to a pathname.

**`Recovered from waitlist` counts** `bookings` rows with a non-null
`waitlist_entry_id` — set once, on claim, by `BookingService::claimOffer` —
starting inside the current calendar month in the tenant's timezone and not
cancelled. The figure is the sum of `price_at_booking`. `starts_at` rather than
`created_at`: the question is how much of *this month's* revenue was recovered,
not how much clicking happened. Pending rows are counted but reported
separately, because a deposit that never lands is money not yet recovered.

`Deposits held` was rewritten: it summed deposits *taken this week*, which is a
different quantity wearing the same label. It is now money held right now
against appointments that have not happened.

**The demo tenant showed £0.00 recovered**, because nothing in it had ever been
*claimed* — three live offers, no claims. `DemoDataSeeder::recovered()` adds
five claimed offers across the month, one still pending. Demo data, not code.

**The demo tenant has two freed slots today, not one.** Priya's 15:00 and
Marek's 15:30 are both genuine gaps, and both get the accent. The mockup shows
one because its invented data has one. Two rows carrying the same *meaning* is
one use of the accent, not two — the mockup's own note makes that argument about
a border, a label and a button on one row.

**Several appointments can be "current" at once.** Four groomers means up to
four people in the chair, and all of them get the ink left border and the extra
line. The mockup shows one because it draws a two-person salon.

## Phase 7 — the admin screens

**The diary.** No approved mockup exists and the three that were built were
rejected, so nothing here is invented: it is `dashboard.html`'s timeline
extended to two dimensions. `ui/TimelineRow` is literally the dashboard's row
and the diary's 375px agenda is built from it — the constraint was consistency
with the approved dashboard, and the strongest form of that is not writing it
twice.

- **Gap-finding.** `ui/GapButton` occupies the minutes it represents, so a
  90-minute hole is visibly three times a 30-minute one, and pressing it starts
  a booking at its first minute for that groomer. A statistic tells you a hole
  exists; it does not tell you where it is or let you fill it. The one aggregate
  that survives sits directly above the space it describes, and it follows the
  staff filter — an aggregate over gaps the screen is not drawing is a claim it
  cannot back up.
- **375px: a single-column agenda, plus a staff filter.** Rejected: a
  horizontally-scrolled grid with a sticky gutter — a phone shows about one and a
  half columns, so comparing groomers still needs dragging, which is the only
  thing columns are for. Rejected: a staff selector alone — the same as this
  without being able to read the day in order. The desktop grid keeps its
  horizontal scroll for a fifth groomer; that is a different problem at a
  different size.
- **Overlaps split the column.** Drawing both blocks full width hides one behind
  the other, which is how a diary reports that everything is fine while somebody
  has two dogs booked at 13:30.
- **Blocks are flat.** The old diary filled each one with its staff member's
  colour, which turned a monochrome product into a spreadsheet and left status
  with nowhere to live. Status is the 2px left border; the staff colour is a 6px
  square beside a name on the Staff screen and nowhere else.
- **The week view is the agenda, not a grid.** Seven days by four groomers is 28
  columns. The week's question is "which days are busy", not "what is Priya
  doing at 14:15 on Thursday".

**Every list is `ui/Table`** — bookings, customers, services, staff,
availability, time off, waitlist, invoices and the import result. Two additions
to the component: `rowLabel`, so the actions menu is announced as "Actions for
Naomi Ellery, 10 March 09:00" rather than seven identical "Actions"; and a
`footer` slot for `bookings-table.html`'s "Showing 1–6 of 12".

- **Staff names in a table are first names.** `--col-staff` is 96px and "Marek
  Kowalski" wraps to two lines in it, turning a 34px row into a 48px one. The
  mockup shows "Ana" and "Marek" for the same reason.
- **Services have no sortable columns.** The order *is* the data — it is the
  order customers see — so a column header that silently reorders it would be a
  control that looks like a view and behaves like an edit. Reordering moved from
  a `draggable` row to `Move up` / `Move down` in the actions menu: WCAG 2.2
  requires a single-pointer alternative to any author-controlled drag, and there
  was none, so the order of the booking page was unreachable by keyboard.
- **Staff colours are six presets, not a colour wheel.** The form was
  `<input type="color">` — an operating-system picker in a monochrome product,
  which guarantees somebody ships a neon-yellow groomer. Same argument as the
  tenant brand presets, same six values.

**Settings.** Every field is a library control with its own error bound and
linked by `aria-describedby`; the timezone is `ui/Combobox`, so "lon" finds
Europe/London; `ui/SaveState` says whether there is anything unsaved, whether it
is saving and whether it saved. Branding and Payments are tabs rather than two
underlined words. The missing error binding was not cosmetic — a rejected value
came back with nothing on screen to say so, and the form silently discarded what
had been typed.

**Fields are white.** Every field in the library carried `bg-paper-sunk` and
only `Combobox` showed it, because `base.css` styles `input`, `select` and
`textarea` at a higher specificity than a utility class — so on real form
elements the class had never done anything. All four say white now, which is
what all four have always rendered.

**Imports.** A drag-and-drop picker that is also a real file input (WCAG 2.2:
drag is never the only way in), the file's own header row mapped against the
columns the importer reads *by position*, a dry run that is the only thing
offered until it has been done, and per-row results on the shared table. The
mapping preview is a deliberately small CSV reader: it exists to show the file
back, and a second full parser in TypeScript would be a second set of quoting
rules for the two to disagree over. `import_preview` became `import_result`,
which carries the kind, whether it was committed, and the counts.

**Profile.** The three forms were already on the library; the page was still
Breeze's shape — three white cards with no heading above them. Hairline sections
instead: containment is a signal and it is spent once. The Breeze *typography*
is phase 8's.

**`check:components` is down to three**, from 21. `CommandPalette` came off the
list by moving *into* the library, which is where a global control belongs; it
was never a screen. Two of the remaining phase labels were wrong and are fixed:
super admin is 9, the auth rewrite is 8.

## Found broken, left alone

Outside the scope of phases 4–7, recorded rather than fixed:

- **`ImpersonationController::stop` uses `redirect()->away()`.** From an Inertia
  visit that is followed by XHR, and in subdomain mode the console is a
  different origin, so it will fail the same way logout did. It wants
  `Inertia::location()`. Not fixed here because impersonation belongs with super
  admin, phase 9.
- **There is no way to remove a waitlist entry.** No `waitlist.destroy` route
  exists, so the row's actions menu can only send you somewhere else. Adding one
  is a behaviour change, not a rebuild.
- **The demo tenant's owner is named after the tenant** — "paw" — so the diary
  has a groomer called paw and the booking page proposes "Soonest with paw".
  ~~Local data, not code, but it makes every screenshot read oddly.~~ Fixed in
  the hardening pass: `DemoDataSeeder` now names the owner Rosa Adeyemi unless
  the existing name already looks like a person's.
- **`Bookings/Index` loads 200 rows with no pagination** and the table sorts
  them client-side. Fine at a salon's scale, wrong at an agency's. When it
  paginates, sorting and the customer search both move to the server with it.

## Found during the hardening pass

Two of these were fixed, because the work could not proceed around them. The
third is a product question and is left alone.

- **`->constrained()->index()` in ten migrations made the app unmigratable on
  MySQL.** *Fixed.* Laravel names the index from the trailing `->index()` with
  no arguments, and the generated name collided across tables — MySQL rejects
  the second with `Duplicate foreign key constraint name '1'`. SQLite ignores
  foreign key constraint names entirely, so every local test run and every CI
  run passed while the production database engine could not accept the schema at
  all. `foreignId()` already creates the index; the trailing call was redundant
  as well as fatal. Found by standing up the e2e MySQL database, which is the
  first thing in this repo's history to migrate against MySQL.

- **The e2e suite was signing in nine times a minute against a five-a-minute
  limiter.** *Fixed.* `RateLimiter::for('login')` allows five attempts per
  minute per email and IP, which is correct. The specs were wrong: each signed
  in for itself. The sixth onward were throttled, the page stayed on the login
  form, and Playwright failed sixty seconds later with a navigation timeout that
  read as a flaky screenshot. `auth.setup.ts` now signs in once and every
  operator spec reuses the session. Total run time went from 9.5 minutes to 30
  seconds, which is the more honest signal that the old suite was mostly waiting
  on a rate limiter.

- **`PublicBookingController::resolveStaff()` silently reassigns a booking to a
  different member of staff.** Left alone. When the requested staff member is
  taken, it finds another who is free at that time and books them instead,
  without saying so. It is why the first version of the 409 race spec produced
  two 201s: the loser was not refused, they were quietly given a different
  groomer. That is defensible for "anyone will do" and wrong for "I want Rosa",
  and the request carries no way to tell the two apart. The race spec now
  exhausts every other groomer first so the guarantee it is testing is the one
  the lock actually provides. Deciding what a staff *preference* means is a
  product change, not a hardening fix.
