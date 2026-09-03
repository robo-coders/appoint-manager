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

---

# Geist, the booking page, mobile, and the demo tenant

## Geist is self-hosted now, and it changed nothing on screen

Three `woff2` files in `resources/fonts/`, `@font-face`d with `font-display:
swap` at the top of `resources/css/base.css` — which both bundles import, so
neither surface can drift — the two sans weights preloaded via `Vite::asset()`,
and `fonts.bunny.net` gone from `preconnect`, from the stylesheet link, and from
both `style-src` and `font-src` in `SecurityHeaders`.

`{$dev}` had to be added to `font-src` as well as `style-src`. While `npm run
dev` is running the stylesheet is served from the Vite origin and so are the
`url()` targets inside it, which makes every font file cross-origin; without the
carve-out the type would fall back to the system face in development only —
exactly the failure self-hosting was meant to end.

**No screenshot baseline moved because of the font, and that surprised us.** The
expectation was that they all would. They did not, because Geist was *already*
being fetched — from `fonts.bunny.net`, since the second foundation pass — so
the baselines were already rendered in it. Self-hosting the same files
rasterises identically. Nine of the fifteen baselines are byte-identical after
this change; the six that moved moved for layout reasons recorded below.

`--font-sans` dropped `'Inter'`. It was there as a second web font for the case
where Geist failed to fetch from a CDN. With Geist on our own origin that case is
"the stylesheet loaded but a same-origin font did not", which no second web font
improves, and the system grotesque is the honest fallback.

`font-synthesis-weight: none` on `.font-mono` and `.numeral`. Geist Mono ships at
400 only, and mono here is almost always *inherited* into a bolder context — the
rail's active nav count sits inside a `font-medium` link. Left to synthesise, the
browser smears the 400 face into a faux 500, the digits stop being the same
width, and that defeats `tabular-nums` in the one place this system depends on
it: a column of money aligning on the decimal.

The three mockups under `public/` were pointed at `public/fonts/` rather than
left on the CDN. They are served from `public/`, so a CDN they can no longer
reach under the app's own CSP would have rendered the *binding reference* for
phases 6 and 7 in the system face. The relative path resolves both from disk and
over HTTP, which keeps them openable with no build step. It costs 142KB of
duplicated binaries, which is the price of the property that makes them useful.

**The rail wordmark still stacks.** This was the open question, because the
original measurement was taken while the page was rendering in the system
fallback. Measured against the real face at 13px/500: "Appoint Manager" sets
**104.63px** on one line against **106.30px** in the system grotesque. Geist is
1.67px narrower and the gap to close was 16.63px, inside 88px of rail. It does
not fit and does not nearly fit; 12px does not rescue it either (96.58px). The
stale "~96px" figure in `NavRail.vue` is corrected to the measured one.

`geist` is in `package.json` and nothing imports it. The three committed files
are byte-identical to the ones in that package, so it was the source — it is now
either a redundant dependency to drop or the thing `@font-face` should point at
instead of committed binaries. Left as found: it arrived uncommitted from
outside this pass, and it is a one-line call either way.

## The booking page

**It is centred vertically now.** `my-auto` inside a `flex flex-1` main, not
`items-center` — an auto margin resolves to zero once the item is taller than the
space it is in, so the picker open at 375 still scrolls from its own top edge,
where `align-items` centring would have pushed the overflow both ways and clipped
the heading beyond the reach of any scrollbar. At 2400 the content occupied the
top quarter and the remaining three quarters read as a page that had failed to
finish loading; centred, the same emptiness reads as margin.

**The page says when an alternative changes groomer.** `AppointmentSuggester`
ranks appointments, and an appointment is a time *and* a person, so an
alternative at a time the proposed groomer cannot work is an alternative with
somebody else. The page said so only by putting a different first name in the
muted column, three rows under a context line naming the proposed groomer. A
customer scanning four near-identical rows had to hold "Maya" in their head and
compare. Alternatives that change groomer now carry a second line — "with Ana
instead of Maya" — which also makes the row taller than its neighbours, so it is
the one the eye stops on.

The booking behaviour is untouched: `resolveStaff()` still reassigns silently and
that stays a product question, recorded above under "Found broken, left alone".
What changed is that the page stopped being quiet about it.

The phrase is composed in the island, not in `ProposalPayload`, and that is a
deliberate exception to "every string a customer reads is built in PHP". The
comparison is against the appointment *currently* on offer, which is client
state: accepting an alternative makes it the proposal and pushes the old proposal
back into the list, at which point a note baked in server-side would describe a
groomer nobody is being offered any more. The names are still formatted
server-side — `staff_first_name`, which also de-duplicated the first-name slicing
`ProposalPayload` was doing twice — so the rule holds where it was about
formatting. There is a unit test for exactly the re-pointing case.

**There is a visible way to change service.** The page picks one and the other
eight were reachable only by opening the day picker and scrolling past a week
grid to a list headed "Something else". "A different service" now sits on the
same line as "Pick another day" — one line of quiet text, two controls, no tenth
element — and opens a disclosure of the price list in place. It is the same
`ServiceChoiceList` the picker uses rather than a second copy, and it marks the
service already on offer instead of omitting it. Still a list, never a `select`:
nine services at nine prices and nine durations are nine appointments, and a
native select shows one at a time with the price and duration thrown away.

## Mobile

**`ui/Table` has a narrow state.** Below `md` it stops being a table and becomes
a list of rows: headline, a second line, one muted value hard right, the same
action menu, the same `cell:` slots. A table is a grid because comparing down a
column is the point, and at 375px there is no column to compare down — the amount
and the row menu sat off the right-hand edge behind a horizontal scroll nobody
discovers, and names broke over two and three lines so the rows went ragged.

It is **opt-in**, per column, per screen. A table whose columns say nothing about
a narrow viewport is left exactly as it was, so a screen nobody has designed a
phone layout for is never silently given one.

The right-hand block is a stack rather than a row, which was the second attempt:
a status badge and a price side by side took 145px of the 271px a 375px row has,
and forced the third and fourth part of the second line to wrap — and a wrapped
part carries its separator, so rows ended with a line reading "· Confirmed" under
a dangling staff name. The separator travels with the part that follows it for
the same reason: as a flex child of its own it could be the last thing before a
wrap, leaving a line ending in a dangling middot that reads as truncated text.

Sorting lives in the header and goes with it. That is a real loss and an accepted
one: sorting 348 customers by booking count is a desk task, and every screen that
adopts this has a search field above it.

**Customers, Bookings, Services and Waitlist** took the narrow state, because all
four had content that was clipped or unreachable at 375. **Staff and Time off did
not**: their rows wrap but everything on them is reachable, and "not broken" was
the bar. Dashboard, Diary, Imports, Settings, Branding, Payments, Profile and the
three detail screens were judged fine at both widths.

`ui/PhoneLink` exists because the user's instruction was "reaching a phone number
should take one tap, not a menu", and two screens show numbers. `tel:`, mono, and
`decoration-rule-strong` rather than the `decoration-rule` `ui/QuietAction` uses
— an 8% hairline under mono figures at 13px is invisible, it disappeared and
reappeared row to row depending on where the baseline landed relative to a device
pixel, and an underline nobody can see cannot be the thing that says "tappable".

`WeeklyHoursGrid` was the one page that scrolled sideways: two `type="time"`
fields have an intrinsic minimum width `flex-1` cannot shrink below, so with a
text button beside them the row measured 38px past the viewport at 375 and past
each column edge at 768, where "Remove" rendered as "Rem". It wraps now.

## Route model binding ran before the tenant context existed

Found while walking the admin screens, and much worse than the thing being looked
for: **every operator screen behind a model-bound route returned 404**. A
customer, a booking, a service — all "not found" for a row you had just clicked
in a list.

`SubstituteBindings` is in Laravel's own middleware priority list and
`ResolveTenant`, a route-level alias, was not, so the sort ran bindings first.
`TenantScope` fails closed with no context — it appends `0 = 1` rather than
reading across tenants, which is correct and is what made the symptom a 404
rather than a leak. `bootstrap/app.php` now puts `ResolveTenant` immediately
before `SubstituteBindings`, which is after `Authenticate`, where it needs to be.

**Why 350 tests missed it, which is the part worth keeping.** Every Feature test
reaches these routes through `actingAsTenant()`, and that helper sets the tenant
context by hand before the request. `TenantContext` is a singleton for the life
of the process, so the context was already there when bindings ran and the order
never mattered. The suite was testing a process that had been set up the way the
middleware was supposed to set it up. `RouteBindingOrderTest` uses plain
`actingAs()` and clears the context first; three of its four assertions fail with
the priority line removed, which was verified rather than assumed.

## The demo tenant shows the product

`DemoDataSeeder::forTenant()` now sets the trial and marks the tenant
Stripe-connected, so neither has to be done by hand per machine.

`tenants.subscription_status` defaults to `trial` and `trial_ends_at` to NULL,
and `hasAdminWriteAccess()` reads the *date*, not the status — so a tenant
created any way other than through the billing flow had no write access and the
whole admin app rendered behind "Admin is read-only until billing is up to date".
`demo:seed --plan=` is applied after the fill, so `--plan=expired` can still show
that state on purpose.

`takesDeposits()` is two columns on the tenant, not a question about which
gateway is bound, so **presenting the demo as connected touches AUDIT C1 not at
all**: the booking page shows "£35.00 total, £10.00 deposit due today" and
Reserve takes the deposit branch. What it does not buy is a completed card.
`StripeConnectGateway` will reject a connected account id Stripe has never heard
of, and the page says so honestly — 503, "nothing has been charged". The real fix
is Stripe test keys in `.env` and a real test-mode connected account, passed with
`demo:seed --stripe-account=acct_…`. There is no third option that does not make
`FakeStripeGateway` reachable outside `testing`, which is what C1 forbids.

`scripts/e2e-setup.sh` passes `--no-deposits`, and it is load-bearing: that suite
books through the public page against obvious fake keys, so a tenant asking for a
deposit returns 503 where `slot-race.spec.ts` asserts 201 and 409. The deposit
copy is asserted where it can be asserted honestly, in `ProposalPageTest` against
a tenant it marks connected itself.

## Two smaller things

`lib/copy.ts` sentence-cases a vertical's own nouns at the point they become a
label. `config/verticals.php` stores them lower case on purpose — most uses are
mid-sentence — and the Customers table shipped a `dogs` header between "Name" and
"Bookings" that read exactly like the bug it was. Fixed at the render site rather
than in the config, so every vertical gets it and none of them lose the
lower-case form their sentences need. Applied at all three sites where raw
vertical copy became a label, which moved the booking page's field label from
"dog name" to "Dog name" and the e2e locator with it.

`check-components.mjs` now strips comments before scanning, which
`check-design-tokens.mjs` has always done and for the same stated reason: prose
is not code. `ServiceChoiceList` documents itself with "never a `<select>`" and
was reported as containing one. A control inside a comment does not render, so
there is nothing hidden by stripping.

## The suite now goes through the middleware

`actingAsTenant()` set `TenantContext` by hand before returning. That one line
is why 350 tests missed the route-binding order bug: `TenantContext` is a
singleton for the life of the process, so the context was already there when
`SubstituteBindings` ran and the ordering could never matter. The suite was
testing a process that had been set up the way `ResolveTenant` was supposed to
set it up.

It does not set one any more. **The whole suite passed unchanged on the flip** —
not one test was asserting on a context the middleware was meant to build, which
is the honest answer to "how load-bearing is this helper". What some tests need
is a context to *write* fixtures with, because `BelongsToTenant` refuses to
create a tenant-owned model without one; that is arrange-phase, it happens
before `actingAsTenant()` is called, and it is unaffected.

So there was nothing to rewrite. Every HTTP feature test now reaches its route
the way a browser does: a session cookie, and `ResolveTenant` doing the work.

### What the new layer caught

`MiddlewareTenancyTest` runs every model-bound operator route on the four
production hostnames, with a 200 for the owning salon and a 404 for a rival's
row. With the priority line removed, eight of its twenty-eight cases fail — and
two of them fail in a way `RouteBindingOrderTest` could not have shown:

**`staff.update` and `availability.sync` did not 404. They returned 403.**

`User` overrides `tenantScopeFailClosed()` to **false**, and has to: login and
password reset look a person up before anybody knows which tenant they are in.
So `User` is the one model whose binding does *not* fail closed. With bindings
running before the context existed, `/staff/{staff}` bound another salon's user
row successfully and the request reached `StaffPolicy`, which refused it on a
tenant comparison. No data crossed — but the only thing standing between a
foreign staff record and a write was the policy, where every other resource had
two independent refusals. Restored to 404, which is where it should have been.

Waitlist entries have no model-bound route at all, so the equivalent assertion
is the one the resource can make: its index lists this salon's entries and no
others. Stated in the test rather than skipped.

## Every tenant is born on a trial

`Tenant::booted()` fills `trial_ends_at` when the caller leaves it null.

`subscription_status` defaults to `trial`, `trial_ends_at` defaults to NULL, and
`hasAdminWriteAccess()` reads the *date*. A tenant that reached the admin with a
NULL trial had no write access at all — the whole app behind "the diary is
read-only until billing is up to date", on the owner's first login.

**Where the bug actually lived, which is not where it was reported.**
`RegisteredUserController` has always set the date, so the paying-customer path
was never broken; `NewTenantTrialTest` registers over HTTP, walks all four
onboarding steps, lands on the dashboard and writes a row, and it passes with
the hook disabled. What was broken is every *other* door: `DemoTenantSeeder`,
the tinker `firstOrCreate` in `scripts/e2e-setup.sh`, anything a support script
would do. Three tests in that file fail with the hook off; the two registration
tests do not. That split is the finding.

**Model boot rather than a column default.** The trial length is
`config('billing.trial_days')` and a database cannot read the config. MySQL will
not take an expression default on a `TIMESTAMP` beyond `CURRENT_TIMESTAMP`
either, so a column default could say "now" but never "now plus thirty days" —
and "now" is an expired trial, which is the bug. A `creating` hook is the one
place that runs for every Eloquent write with the config loaded. It fills only a
null, so the factory, the registration flow and `DemoDataSeeder::billing()` all
keep the last word on a value they set deliberately.

A backfill migration repairs rows that already exist, and only the exact broken
state: `trial_ends_at IS NULL AND subscription_status = 'trial'`. A tenant on
`active` is supposed to have no date; one on `past_due` has ended its trial and
must not be handed a fresh one.

## demo:seed will not half-configure deposits

It used to seed `acct_demo_not_a_real_account` and print a paragraph saying that
paying would not work. The booking page then showed the deposit line, Reserve
took the deposit branch, and Stripe rejected the account — a 503 at the last
step of the one flow the demo exists to show, discovered in the browser, reading
as a broken product rather than as an unset variable.

It is a precondition now: with deposits on, `STRIPE_KEY`, `STRIPE_SECRET`,
`STRIPE_WEBHOOK_SECRET` and `--stripe-account=acct_…` must all be present or
nothing is written. The refusal names every missing piece at once, gives the
command that produces each, and names `--no-deposits` as the way out.

It checks presence and shape, not validity — verifying an account id would mean
a network call inside a seeder. A wrong-but-well-formed id still fails at
Stripe, and the booking page already says so honestly (503, "nothing has been
charged"), which is AUDIT C5 working as designed.

`--plan-only` no longer touches the Stripe columns. It says "set the billing
state and change nothing else" and it now means it — it was overwriting them on
its way past, so looking at the read-only banner silently reset the demo's
payment setup. It also needs no Stripe keys, because flipping a tenant to
`expired` is not a request to configure payments.

The full setup — both API keys, the CLI webhook secret, creating and onboarding
a test-mode connected account, and the test cards — is in DEPLOY.md under "Demo
deposits on test keys".

## geist is gone from package.json

Nothing imported it. The three woff2 files in `resources/fonts/` are the source
of truth: `base.css` declares them by relative path and `partials/head.blade.php`
preloads two of them through `Vite::asset()`, so Vite hashes and serves them
from our own origin — which is what let `font-src` drop to `'self'` and the font
host disappear from the CSP entirely. The npm package would have been a second,
unhashed copy of the same bytes reached through a bundler import, and it was not
reached at all.

## The User exemption is the size of the reason for it

`User` overrode `tenantScopeFailClosed()` to false. It was the only model that
did — nothing else in `app/` touches that method, which is worth stating because
"is this the only one" was a real question and the answer is yes.

The reason for the override is four method calls. Logging in, restoring a
session from its cookie, a remember-me token and a password reset all have to
find a person by a credential at a moment when there is no tenant context and
there cannot be one: the context is derived *from* the user that has not been
found yet. That is a genuine requirement and it is not going away.

What the override bought instead was a class-wide property. Every `User` read in
the application became a cross-tenant read — route bindings, controllers,
console commands, seeders, anything. `MiddlewareTenancyTest` caught the shape of
it: `staff.update` and `availability.sync` answered **403** where every other
resource answered 404, because the rival's row bound successfully and
`StaffPolicy` was the only thing between it and an `update()`.

**So the exemption moved to the four methods.** `App\Auth\IdentityUserProvider`
extends `EloquentUserProvider` and lifts `TenantScope` in `newModelQuery()` —
the single choke point that `retrieveById`, `retrieveByToken` and
`retrieveByCredentials` all read through. `config/auth.php` points the `users`
provider at it, which is both the `web` guard and the password broker, and the
`User` model now fails closed like everything else. Writes needed nothing:
`updateRememberToken` and the rehash go through `Model::save()`, whose update
query carries no global scopes anyway.

**Why not the other two.** *Explicit binding on the operator routes* fixes
`/staff/{staff}` and `/availability/{staff}` and stops there — a route added
later that binds a user gets the old behaviour back, silently, and the fix lives
in a provider a long way from the thing it is about. It also leaves
`User::query()->where('email', …)` in a console command reading across every
salon, which is the same hole through a different door. *Tenant-scoped
`resolveRouteBinding()` on `User`* is better — it covers every route, present
and future — but it narrows the **symptom** rather than the exemption: the class
is still fail-open everywhere that is not a route parameter, and the queue
worker and the support script are exactly where nobody is watching. Only moving
the exemption onto the auth surface makes the sentence "`User` is scoped like
every other model" true, and that sentence is the one that has to be true for
`ScopeFailClosedTest` to be able to list `User` alongside the other eight.

### What the policy-disabled test proves, and what it does not

`Gate::before(fn () => true)` allows every authorization check in the process —
the `authorize()` inside `AvailabilityController::sync`, and the
`can('update', …)` inside `UpdateStaffRequest::authorize()`, which is where
`staff.update` was actually being caught. The test asserts the disarm worked
before it asserts anything else, because a test that quietly failed to disable
the policy would pass and mean nothing.

**Disabling the policy on its own was not enough, and finding that out is most
of the value here.** With `ResolveTenant` running before `SubstituteBindings` —
which it has since the priority fix — there is always a context by the time the
binding runs, so `TenantScope` narrows the query to the operator's own salon and
the rival's row is out of reach whether `User` fails closed or not. The first
version of this test passed with the old exemption restored.

The exemption only ever bit where there was **no context**. Before the priority
fix that was every request; it is still every console command, queue job and
support script. So the test that carries the claim drops `ResolveTenant` as well
as the policy, putting the process back in that state with nothing left but the
model. Restoring `tenantScopeFailClosed(): false` turns it from a 404 into a
**302** — the rival's staff record renamed by a stranger, redirect and toast and
all. That is the assertion.

The narrowing is only honest if the auth surface still works, so three more
pin the other side: a login from a cold process with no context anywhere, a
password-broker lookup by email, and the impersonation handoff.

### The one binding that still crosses tenants

`/impersonate/{user}`. A super admin has no tenant — that is what makes them one
— so there is no scope the target could be found inside, and with `User` failing
closed the implicit binding returns nothing. It is now an explicit
`Route::bind('user', …)` with `withoutGlobalScopes()`, declared next to
`Route::model('staff', User::class)` so both of the application's `User`
bindings are in one place and it is visible that exactly one of them is the
exception. Authority for that route was never the tenant scope: it is the
signature on the URL, the single-use nonce, and the super-admin recheck in the
controller, and all three still run.

### One test was reading across tenants and had to say so

`RegistrationTest` asserted on the new owner with `User::query()->where('email',
…)` from outside the request, where there is no context. It is a background
process by the same definition `ScopeFailClosedTest` uses, and it now names the
tenant it means. Nothing else in 410 tests needed changing.

## A tenant with no Stripe account is a normal state

`PublicBookingController::store` type-hints `BookingService`, which type-hinted
`StripeGateway`, whose binding refuses to resolve without credentials — AUDIT C1,
and the refusal is correct: the alternative is a fake gateway that accepts
forged webhook signatures. But the refusal happened while the container was
assembling the controller's arguments, so the POST died **before a line of its
own code ran**, for every tenant on the page, whether or not the booking
involved money. A salon that takes no deposits got a stack trace out of a code
path that never needed a gateway at all.

`StripeGateway` is no longer a constructor dependency of `BookingService`. It is
resolved at the point of use, by a private `gateway()`, inside the two places
that already know what to do when payments cannot be reached. **C1 is untouched
in both directions**: the same binding is asked the same question and gives the
same refusal, and `FakeStripeGateway` is still reachable in `testing` only. What
changed is that the question is now asked somewhere an answer is possible.

The two answers:

- **No deposit.** The gateway is never resolved. 201, confirmed, no payment
  object — which is what the tenant asked for and what the page has always been
  designed to show.
- **A deposit, and no credentials.** `PaymentsNotConfiguredException` — a
  `RuntimeException` subclass, so C1's "refuse to boot" behaviour and
  `GatewayBindingTest` are both unchanged, but now a *named* condition. The
  booking service releases the slot and raises `PaymentSetupFailedException`,
  which the existing render hook turns into a **503** carrying its message
  verbatim to the island.

That message is not the existing one. "Please try again in a moment" is right
for a Stripe outage and a lie for a platform with no Stripe keys, where the
customer would retry all afternoon; `notConfigured()` says card payments are not
available on this page, nothing has been charged, and to call the salon. Both
release the slot and charge nothing. Only one of them is worth waiting for.

`UnconfiguredPaymentsTest` has to leave the `testing` environment to see any of
this, since the fake gateway always resolves there — which is the same reason
the bug was found in a browser and not in 400 tests. Leaving `testing` also
leaves the CSRF bypass, so those requests carry a real token rather than
disabling `ValidateCsrfToken`, which would have been shorter and would have
quietly reduced what they cover. Two assertions in that file are about C1 rather
than about the booking: with no credentials, the container still refuses to hand
out a gateway, and the webhook still refuses a forged signature.

## pest --parallel is the same suite as pest

`aDiarySalon()` and `aDiaryBooking()` were declared in `DiaryFreedSlotTest` and
used from `DiaryGapsTest`. Serially every file is loaded eventually and in a
stable order, so borrowing a helper across files works by accident. In parallel
the two files land in different workers and the borrower dies with `Call to
undefined function` — a fatal, not a failure, so it takes the worker with it.
The suite was green and fatal at the same time, on the same code, depending on
how it was run.

They live in `tests/Pest.php` now, which every worker loads before any test
file. That is a rule rather than a one-time move, so `TestHelperScopeTest`
enforces both halves of it: a helper called from another file must be declared
in `Pest.php`, and no two files may declare the same name — a redeclaration is a
hard fatal in whichever worker loads both and invisible in the ones that load
one, which is why `MiddlewareTenancyTest` declares its own `onTheRealHosts()`
rather than borrowing `SurfaceRoutingTest`'s `withSubdomains()`.

It reads with `token_get_all()` rather than a regex, for the reason
`check-components.mjs` strips comments before scanning: prose is not code. Three
of the eight matches a naive grep finds in this suite are helper names inside
docblocks explaining this exact problem, and a guard with three false positives
on the day it ships is a guard somebody deletes.

**Parallel is the default now.** 410 tests, eight cores: **5.0–5.4s parallel
against 14.7–14.9s serial**, measured three runs each. That is 2.8×, and it is
the wrong way round to leave — the saving grows with the suite and the cost of a
20-second feedback loop grows with it too. `composer test` passes `--parallel`,
and `npm run test:php` is the shorthand. `vendor/bin/pest` still runs serially
and is still the better mode for reading a failure, because parallel interleaves
eight workers' output.

## public/fonts duplicates resources/fonts on purpose

Committed, both of them. An earlier note in this file said `public/fonts/` was
"referenced by nothing" and that was wrong: `public/mockups/dashboard.html`,
`bookings-table.html` and `booking-proposal.html` all `@font-face` it at
`../fonts/`.

They cannot share the app's copy. `resources/fonts/` is reached through the Vite
manifest — `base.css` declares it by relative path and `head.blade.php` preloads
two files through `Vite::asset()` — so the served filenames are content hashes
that change on every build, and a static HTML file cannot name them. And they
cannot reach a CDN: `font-src` is `'self'`, which is the whole reason these are
self-hosted rather than loaded from Google. So the mockups need a copy at a
stable path, and `public/fonts/` is that path.

140KB duplicated is the price of the mockups rendering in the typeface they are
mockups of. 140KB untracked was the alternative, and it means the mockups
render in a fallback on every machine but this one.

## Found broken, outside this scope, untouched

**Seven e2e screenshot baselines are stale.** `diary-375`, `dashboard-375/768/1280`,
`bookings-table-768`, `booking-proposal-375/768`. They are height changes, not
pixel noise — `diary-375` expects 1709px and gets 900px, `dashboard-375` expects
2381 and gets 2395 — so they are the in-flight UI work in the tree with the
baselines not regenerated. Verified as pre-existing by stashing every change in
this pass and re-running: byte-identical failure set, before and after.

The functional half of the suite is green. Because the `public` project depends
on `operator`, those seven failures also skip `slot-race.spec.ts`, which is the
one spec the e2e suite exists for — so a stale snapshot currently hides the 409
race. Run with `--ignore-snapshots` and all 20 pass, including both race specs,
which is how the booking POST was confirmed end-to-end against the real gateway
class here.

**`PaymentSettingsController` still resolves `StripeGateway` at bind time.**
`connect()`, `refresh()` and `returned()` type-hint it, so on a platform with no
Stripe credentials those three operator routes are a 500 rather than a sentence
— the same shape as the booking bug above, in the screen an owner reaches to set
payments up. It is not the booking flow and it was left alone. The fix is the
same one: resolve it in the method body and let the screen say what is wrong.

---

# Phases 8 and 9

## Carry-over — `PaymentSettingsController`

`connect`, `refresh` and `returned` type-hinted `StripeGateway`, whose binding
refuses to resolve without platform credentials (AUDIT C1). Method injection
made that refusal happen while the container built the action's arguments —
before a line of the action ran — so all three were a 500 with a stack trace,
and `refresh`/`returned` are the two URLs **Stripe itself** sends the owner back
to. Resolved at the point of use now, inside `gateway()`, the same shape as
`BookingService::gateway()`. C1 is untouched: same binding, same question, same
refusal, asked somewhere an answer is possible.

**`show` was never one of the three.** It carries no gateway hint, so GET
`/settings/payments` already rendered. The brief said the screen was a 500; it
was the three actions on it. `show` now also asks the binding whether Stripe is
reachable and passes `reachable`, so the screen does not offer a button whose
only outcome is an error — that is the part of `show` that is new.

`tests/Feature/Payments/PaymentSettingsUnconfiguredTest.php`. Reverted, three of
its tests fail with `Expected [201, 301, 302, 303, 307, 308] but received 500`.

## The seven stale e2e baselines, and why they were stale

Regenerated: 7 of 14 changed, exactly the 7 that were failing — `dashboard` at
all three widths, `diary-375`, `bookings-768`, `booking-proposal` at 375 and 768.

**The cause was not page height.** Two separate causes, both worth recording
because both will recur:

- **The seed is deterministic per day, not per run.** `DemoDataSeeder` calls
  `mt_srand(20260310 + $tenant->id)`, so a given tenant on a given day produces
  identical data — verified by seeding twice and counting: 199 bookings, 72
  customers, both times. But the day loop is relative to `now()`, so which days
  are Saturdays shifts, `mt_rand(4, 6)` and `mt_rand(2, 5)` consume different
  amounts of the stream, and the data differs across dates. The dashboard also
  renders the server's own date in its heading. **These baselines go stale every
  midnight**, and no amount of freezing the *browser* clock touches it —
  `screens.spec.ts` freezes `Date.now()` in the page, and every value in
  question is computed in PHP. Fixing it means pinning the server clock, which
  is a change to how the app boots; left alone, per the standing rule.

- **A baseline can capture the slot race's own bookings.** The regenerated
  `dashboard-*` baselines contained two extra rows — "Bramble — Full groom —
  small dog" with Rosa at 09:00 and 10:00, which is `slot-race.spec.ts`'s
  fixture — and `Bookings 79 / Customers 74` against a clean run's `77 / 72`.
  The `public` project's `dependencies: ['operator']` guarantees the *ordering*
  within a run; it guarantees nothing about the state a run starts from. Any
  `playwright test --update-snapshots` that does not reseed first can bake a
  previous run's mutations into a baseline. Regenerate with
  `./scripts/e2e-setup.sh && npx playwright test --update-snapshots`, and verify
  by reseeding and running again — which is what was done here, twice.

- **`--update-snapshots` silently left one baseline stale.** `login-768` did not
  regenerate on a run where its content had definitely changed; deleting the
  file and re-running wrote the correct one. Cause not established. The safe
  procedure is `rm` the baselines you intend to regenerate rather than trusting
  the flag.

## Phase 8 — auth

**`GuestLayout` is the phase.** Every auth screen was already on the phase 2
library — the brief's "undisguised Breeze, rounded-lg inputs" was out of date by
several phases — with exactly one exception, the raw `<input type="checkbox">`
on Login that kept it on the `check:components` list. What was undesigned was
the *page*: `max-w-sm rounded border bg-white p-6` centred in a `min-h-screen`
flex, which is the small box marooned in a large viewport the brief names.

It is a full-bleed sheet divided by one hairline now: a working column on the
product's own left edge, and a quiet column on `paper-sunk` — the nav rail's
surface — carrying one true sentence. Never centred at any width, so there is no
width at which it collapses back into a box. Below `lg` the quiet column is
dropped rather than stacked.

Two things were rebuilt after looking at them at 1280 and 768:

- **The vertical anchor was reversed.** Built top-anchored on the argument that a
  one-field form and a five-field form should start on the same line; rendered,
  that bought consistency nobody can see (you never view Sign in and Confirm
  password together) and cost 460px of dead space between the button and the
  foot, with three elements pinned to three corners. The middle centres at `md`
  and up now; the lockup and the foot stay pinned.
- **The quiet column moved from `md` to `lg`.** At 768 it got 208px after the
  working column's basis, so the sentence set three words to a line — "The
  diary, / the / deposits, / and the" — which is a column of rag, not a
  paragraph.

Also: the panel headline dropped 20 → 17px, because at 20 it carried more
visual mass than the 24px `h1` it sat opposite, and a quiet column that outranks
the working one is an advert.

**Registration is five screens and now says so.** `App\Support\SetupSteps` holds
the list once; `Auth/Register` is step one and `OnboardingLayout` continues the
same page, same left edge, same lockup, same progress filling in. `ui/StepProgress`
renders it two ways: the named list in the quiet column at `lg`, and one line
plus a segmented hairline meter at 375. Named rather than numbered — "3 of 5"
says how much is left, "Services" says what it is. No percentage: on a five-step
form that is a computed number pretending to be information.

**Back is a real control and loses nothing**, because each step saves on continue
and `OnboardingController::show` re-reads from the database. The rail links only
to steps that are done or current: a link to step five from step two is a link
to a form with nothing in it.

**The final step hands over a diary with something in it.** The hours step
carries an optional first appointment — name, email, service, staff, time — and
`updateHours` writes it *after* the availability rules, because `BookingService`
checks the slot against those rules and at that exact moment they are the ones
in the same request. Skipping is one click and lands on the same diary.
`tests/Feature/Onboarding/FirstAppointmentTest.php`.

**The email is asked for rather than invented.** `customers.email` is NOT NULL
with a unique index on `(tenant_id, email)`, so this product has no way to hold
a client who is only a name and a phone number — the first version of the form
tried and died on the constraint. That is a real product limit, recorded below.

Other changes worth naming:

- **`AppLogo` owns the wordmark in both shapes.** It set the name on one line and
  `ui/NavRail` hand-rolled a second, stacked copy with its own SVG. Two files
  drawing the product name is how they drift, and auth would have made it three.
  `stacked` is the rail's shape and the 148px measurement that forces it lives
  next to the markup. The brief said not to solve it twice; one component now
  solves it once, in both cases.
- `--auth-col` and `--auth-form` are tokens, not bracket values. The three
  mockups' `:root` blocks gained them, because `check:design` asserts the mockups
  carry every token in `tokens.css`.
- A failed sign-in is a `Callout` above the fields with `role="alert"`, not a
  12px line under the email field. Laravel reports it on the `email` key, so it
  used to render at the same weight as "enter a valid email address" for a fact
  about the whole attempt.
- The trial length and the price are built in PHP from config. The first draft of
  the registration page said "£29 a month"; the plan is £39 and
  `config/billing.php` has said so all along.
- `Auth/Login.vue` came off the `check:components` list. `MAX_PENDING` 3 → 2.

## Phase 9 — super admin

**The one idea: the screen answers "who is in trouble" before anything else.**
Sorted by name it answered nothing — a hundred salons in alphabetical order is a
directory. `needs_attention` is computed in PHP from the subscription state and
the trial date, the list opens on it, and the state is a phrase in its own
column rather than `plan status comped` joined by spaces.

`Pages/SuperAdmin/Index.vue` was a hand-rolled `<table>`, five bare underlined
`<button>`s per row and two placeholder-only `<input>`s. It is `ui/Table` with
one `ui/Menu` per row and the clone form in a `ui/SlideOver`. `Messages` and
`Failures` were never on the allow-list because they hand-rolled nothing — they
had no design at all. `Failures` rendered `JSON.stringify(failed_jobs, null, 2)`
into a `<pre>`, so with nothing broken it printed `[]`.

**`data-density='console'` had never been set.** `tokens.css` has carried the
block since the density pass and no surface applied it, so the one surface it
was written for rendered at operator density. Set once on the surface's own root
in `app.blade.php`.

**Impersonation is the dangerous action and looks it.** Not a fifth underlined
word in a row of five: `danger` in the row menu, behind a confirm that names the
salon *and the person* and whose button says "Sign in as Ines Duarte" rather
than "Confirm". Stopping it was already unmissable from inside the tenant's app
— `ui/RailUserMenu` becomes a `--danger` block — and it now works, see below.

Two things were rebuilt after looking at 375:

- **The send log dropped the message.** The narrow layout showed recipient,
  timestamp and status, and not the body — the only reason anyone opens a send
  log. Every row's timestamp also read "2m ago", because the seed writes them in
  one go. Body and time share the second line now.
- **The failures screen dropped the exception.** Same mistake: job name and "3h
  ago", with the class and message gone. They are the second line now, and long
  class names carry `break-all` — `Symfony\Component\Mailer\Exception\TransportException`
  ran off the right edge of a 375px viewport.

Fixed here because DECISIONS.md queued them for this phase:

- **`ImpersonationController::stop` used `redirect()->away()`.** From an Inertia
  visit that is XHR: the client receives an HTML document for a different origin
  it has no page component for. In subdomain mode the browser refuses it; without
  subdomains it paints the console inside the salon's shell. `Inertia::location()`
  now, the same fix `AuthenticatedSessionController::destroy` documents for
  logout. Reverted, the test fails with `Expected [409] but received 302`.
- **The console had no working logout.** It had one, aimed at `route('logout')`
  — the app surface's route — while the console has its own session and its own
  `admin.logout`. `AppLayout` picks by whether there is a tenant, which is the
  same condition the rail's link list already branches on.
- **`EnsureSubscriptionWrite` applied a tenant's billing lock to a super admin.**
  It bypasses now when *not* impersonating, and stays in force while
  impersonating — "I want to see exactly what she sees". `impersonator_id` in
  the session is the only thing that separates the two cases, because the
  authenticated user while impersonating *is* the owner.
- **A guest on an admin route was sent to the app login.** `Surface::fromHost`
  returns `App` unconditionally when subdomain routing is off, so every surface
  shared one answer. `Surface::current(host, path)` added, which answers by host
  under subdomain routing and by path prefix otherwise.

`Pages/SuperAdmin/Index.vue` came off the `check:components` list.
`MAX_PENDING` 2 → 1. The one that is left is `marketing/partials/cta.blade.php`,
cleared by **phase 11**.

## Fixed outside the phases, and the reasoning for doing so

**Every focused input in the product wore Tailwind blue.**
`@tailwindcss/forms` styles `[type='text']:focus` with `border-color: #2563eb`
— blue-600, in a palette with no blue in it — at a specificity above
`base.css`'s `:focus-visible` rule. Found by sampling a pixel off a screenshot
of the registration page: `rgb(37, 99, 235)`.

This is the standing rule's "found broken outside scope", and it was fixed
rather than recorded. The reason: it is an off-token colour on every screen in
both phases, on the exact surfaces the brief asked to stop looking generic, and
a Tailwind-blue focus border is about the most generic mark a screen can carry.
Revert `base.css`'s focus block if that call was wrong.

`check:design` cannot catch it and never could — the colour is generated by a
plugin at build time and appears in nothing we author. Scanning the *built*
stylesheet does not work either: the plugin's declaration is still in the file,
it simply loses. It is asserted in a real browser on a real focused control —
`tests/e2e/auth.spec.ts`, "the focus ring is the accent, not the form plugin's
blue" — which is the only place the question has a true answer.

**`ui/MenuItem` now closes its `ui/Menu`.** The panel had an `@click` and a
click on an item does bubble through it, but the menu stayed on screen behind
the impersonation confirm's overlay — a dialog that exists to be read carefully,
read through a second surface. The item closes the menu itself now, before its
own handler runs, so the outcome does not depend on what the handler does next.
Provided by injection, so every menu in the product gets it.

**`WeeklyHoursGrid` lost `min-w-0` on its time fields.** It was there so the
fields would give up spare width before Remove wrapped. That traded one clipping
for another: `type="time"` in a 12-hour locale renders "05:00 PM", and at 375
inside the onboarding column each field got about 114px against an intrinsic
120 — so the meridiem was cut off and a salon closing at five in the afternoon
read as closing at five in the morning. A wrapped Remove is a layout decision; a
missing PM is a wrong time.

## Found broken, left alone

- **The e2e snapshot baselines go stale every midnight.** See above. The fix is
  a pinned server clock, which is a change to how the app boots.
- **`BillingController::index` hardcodes `'£39'`** rather than reading
  `config('billing.monthly_price_pence')`, which is 3900. The two agree today.
  `RegisteredUserController` builds its sentence from the config, so the
  registration page and the billing page now derive the same number two
  different ways.
- **`customers.email` is NOT NULL and unique per tenant.** There is no way in
  this product to hold a client who is only a name and a phone number, which is
  what a salon's paper book is mostly made of. It forced the onboarding first
  appointment to ask for an email. A product question, not a bug.
- **`e2e-setup.sh` now seeds three extra tenants and a super admin.** They exist
  so the console screenshot shows the states the screen sorts on. Every operator
  query is tenant-scoped so no operator spec can see them, but it is one more
  thing the e2e database contains that production does not.

---

# Phase 10 — the surfaces the app sends outward

## Error pages

`resources/views/errors/` did not exist. Every 403, 404, 419, 429, 500 and 503
was the framework's stock grey page — the product's visual language ending at
exactly the moment somebody most needs to trust it.

**The person who hit the error is not one person**, and that is the phase's one
idea. `App\Support\ErrorPage` resolves the surface and returns the wording *and*
the ways out for that audience:

- **An operator on `app.`** has the whole product behind them and gets it back:
  today's diary, all bookings, customers. A list of real destinations, not one
  "Go home" button — home is a different place on every surface.
- **A customer on `book.`** followed a link a salon gave them and has never
  heard of us. Their 404 says the *booking link* is wrong, points them at the
  salon, and offers **no links at all**. Our marketing site sells appointment
  software to salon owners; they are not one, and "Appoint Manager home" is a
  tap that helps nobody. A dead end that is honest about being one beats a
  button that wastes a tap.
- **The console on `admin.`** is us, at 2am, and gets one sentence: "No route
  matches."

The status code is a mono eyebrow, not a 200px numeral. The number is the least
useful thing on the page for the person reading it — they know something is
wrong and are trying to find out *what* — and it survives only because it is the
one thing worth quoting to support.

### The shell links nothing and queries nothing

`errors/layout.blade.php` inlines its CSS, reads no manifest, mounts no Inertia
and makes no query. A 503 is served while the database is down and, during a
deploy, while the Vite manifest is halfway through being replaced — so `@vite()`
on an error page is a page that 500s at the one moment it is the only page left.

The palette is read from `tokens.css` by `App\Support\DesignTokens` rather than
written out a second time, which is the hazard `check:design` exists to catch
elsewhere. Geist is not loaded: a `@font-face` pointing at a hashed build asset
is one more thing to be missing, and the system grotesque is what `--font-sans`
already falls back to.

**Proven rather than asserted.** With MySQL stopped (`mysqladmin: connect to
server at '127.0.0.1' failed`), `/diary` and `/book/paw` both returned 503 with
the right per-surface wording, no stylesheet link, no script tag and no build
asset.

### `php artisan down`, never `--render`

`--render` pre-renders the view once, in the CLI, and serves that snapshot to
everybody. The console has no request, so the surface resolves as the operator
app and a customer on the booking host is told the product "is being updated"
rather than that ringing the salon is faster. Recorded in `DEPLOY.md`.

### 419 — the one that mattered most

An operator whose session went stale mid-shift landed in a stock "419 | Page
Expired" with no link, no explanation and the thing they were doing gone. It now
stores the **referrer** — not the URL that failed, because a 419 is almost
always a POST and sending somebody back to `POST /bookings` helps nobody — as
`url.intended`, so signing in returns them there. Only same-origin referrers are
kept: `url.intended` is followed after login without further checks, so an
attacker-controlled `Referer` would be an open redirect handed to us for free.

### Four things that were quietly wrong, and are worth knowing

Each of these produced a *silently* wrong result rather than an error, which is
why they are written down:

- **`Handler::render()` calls `prepareException()` before `renderViaCallbacks()`.**
  A render callback type-hinted on `TokenMismatchException` is registered and
  never fires, because by then it is an `HttpException(419)`. Registered against
  `HttpException` with a status check instead.
- **The view is `errors::404`, not `errors.404`.** `renderHttpException()` looks
  up the *namespaced* view. A `View::composer('errors.*', …)` matches by hand
  and never through the handler — so `$page` was undefined, the view threw, and
  the handler fell back to the stock page. Registered for both spellings.
- **`nunomaduro/collision` rebinds `ExceptionHandler` whenever the app runs in
  the console**, which is the whole Pest suite. `app(ExceptionHandler::class)
  ->render(...)` in a test renders *Symfony's* built-in page, not ours. The
  tests go over real HTTP for this reason; the object a test can reach by hand
  is not the object that serves a browser.
- **An unclosed `@section` leaves an output buffer open.** Three views had
  `@section('extra')` with no `@endsection`. Every test still passed and every
  one was marked "risky" — a green suite quietly warning about output buffers is
  a suite people stop reading.

### The bfcache header, cleared

`SecurityHeaders` now sets `no-store` on authenticated HTML. Laravel's default
`no-cache, private` is correct server-side but does not disqualify a response
from the browser's back/forward cache, so after logging out the back button
could still *paint* the last screen of somebody's diary with their clients'
names on it. Scoped to responses that carry a session and are `text/html`: it
also forbids ordinary caching, and the booking page and marketing site are the
two things here that most want to be cached.

`DECISIONS.md` had queued this as "a behaviour change on every response". It is
not — it is a behaviour change on authenticated HTML, which is the only place
the bug existed.

## Mail templates

Seven templates on stock `<x-mail::message>` markdown, never touched by any
phase, and the first thing a customer sees after booking.

**Email is a different medium and this does not chase pixel parity.** Outlook on
Windows renders with Word's engine: no flexbox, no grid, no `max-width` on a
`div`, stylesheets largely discarded. So the layout is nested tables with inline
styles, and every divergence follows from that. What is kept is the palette, the
hairline instead of a border-and-shadow, one ink action, sentence case, and mono
tabular numerals for every time and every amount — which is the one place email
keeps faith with the app exactly, because a fallback mono face still aligns on
the decimal.

Where it deliberately diverges:

- **A dark palette exists.** `DESIGN.md` says light only and that stands for the
  app. It cannot stand for email: Apple Mail and Outlook repaint a message for
  dark mode whether or not we have an opinion, and an auto-inverted warm-paper
  email comes back muddy blue-grey with the ink action flipped to near-white on
  near-white. Specifying beats being repainted. The values live in `tokens.css`
  under `[data-scheme='mail-dark']` — outside `:root` so the mockups are not
  asked to restate an email's dark colours — and they are the same roles, not a
  second design.
- **No web font.** `@font-face` in email is unreliable and most clients will use
  the fallback stack anyway, so the stack is named honestly.
- **A fixed 560px table.** The app has fluid columns; email has one, because
  Word cannot do anything else.
- **No unsubscribe link.** All seven are transactional — a confirmation, a
  reminder, a cancellation — and there is nothing to unsubscribe from that is
  not the appointment itself.

**Every message has a plain text part**, built from the same `App\Support\MailCopy`
values as the HTML so the two cannot drift. It is not an afterthought: somebody
reads it, and every spam filter scores a message that has none.

Three things found by rendering and looking, which is the only way they would
have been found:

- **The plain text part was HTML-escaped.** Blade escapes by default, which is
  right in HTML and wrong in a document with no markup — a salon called "Paw &
  Order" arrived as "Paw &amp; Order".
- **The dark-mode button label was invisible.** The dark block swapped the fill
  to light and the *cell's* colour to ink, but the anchor carries an inline
  `color` which beats a class on its parent. White on near-white; the only
  action in the message, gone.
- **An anonymous component has its own scope.** Only `$slot` and the attributes
  named on the tag cross into it, so a value merely present in the Mailable's
  `with()` silently falls through to the shell's default — which is how the
  operator's agenda came out signed "Sent by Appoint Manager on behalf of
  Appoint Manager".

## The rotting baselines, and the stale one

### Why they rotted

`screens.spec.ts` froze the *browser* clock. Everything in those snapshots that
moves is computed in **PHP**: the dashboard's own date heading, the booking
page's "first available", and the demo seed itself, which walks a window of days
relative to `now()` — so which of them are Saturdays shifts and `mt_rand(4, 6)`
versus `mt_rand(2, 5)` consumes a different amount of the seeded stream.
Deterministic per day, different every day. Six snapshots went red every morning
for no reason anybody could act on, which is worse than no gate.

The day is an input now: `FREEZE_NOW`, read by
`AppServiceProvider::freezeClockForDeterministicRuns()`, set by both
`scripts/e2e-setup.sh` (which seeds with it) and `playwright.config.ts` (which
serves with it). All three agree — a database seeded on one date and rendered on
another is the same bug with extra steps. Three guards, because a frozen clock
in production would be a very quiet catastrophe: production refused outright,
the value must parse, and nothing happens unless the variable is set.

Two consequences of freezing a clock, both of which broke the suite before they
were understood:

- **Cookies expire in the past.** Laravel stamps the session cookie and
  `XSRF-TOKEN` with `now() + session.lifetime`, and Symfony then computes
  `Max-Age` from the *real* `time()` — so an expiry in the real past clamps to
  `Max-Age=0` and the browser deletes the cookie on receipt. Signing in rendered
  "419 Page Expired" in an iframe over the login form. `SESSION_LIFETIME` is ten
  years for the e2e run.
- **Rate limiter windows never advance.** `RateLimiter` stores hit counts in the
  cache and every store computes expiry from `now()`, so "five login attempts
  per minute" becomes "five login attempts, ever". The suite failed on its
  fourth sign-in with a 429. `e2e-setup.sh` clears the cache after migrating,
  which makes the budget per *run*: `auth.setup.ts` spends one for
  `owner@paw.test` and `logout.spec.ts` spends three more, four of five. **A new
  spec that signs in as that owner is the one that breaks this**, and it will
  look like a flake and will not be one.

### Why `--update-snapshots` left a stale baseline

This was the one that could not be explained last phase, and the answer is worse
than the symptom.

Playwright compares pixels in YIQ space against `35215 × threshold²`. At its
default `threshold: 0.2` that is a maxDelta of **1408.6**. This palette's two
page surfaces — `--paper` `#FCFBF9` and `--paper-sunk` `#F4F2EE` — are a delta
of **40.8** apart. So every pixel of a `paper-sunk` panel appearing or
disappearing counted as *unchanged*. When the auth layout's quiet column was
removed at 768, only its hairline and its text registered at all — about 1.3% of
the frame, under `maxDiffPixelRatio: 0.02` — so the comparison passed and
`--update-snapshots` correctly wrote nothing.

It was never a Playwright bug. **The gate could not see the design system.**

`threshold` is `0.03` now, a maxDelta of 31.7, which is the loosest value that
registers paper against paper-sunk. Verified by flipping the auth panel's
surface and re-running: **710,744 pixels (56% of the frame)** now differ where
the old threshold counted zero.

**`--paper` against `--white` is a delta of 8.3 and cannot be caught by any
usable threshold at all** — catching it would need `threshold < 0.016`, which is
effectively zero tolerance and would fail on font rasterisation on any other
machine. That is not pretended to be solved: `expectSurface()` in
`tests/e2e/support.ts` reads computed background colours and asserts them
against the tokens, which is exact and machine-independent. The auth spec uses
it for both columns; the errors spec uses it for all six pages.

The working rule that came out of this: **delete a baseline you intend to
regenerate.** `--update-snapshots` only writes when the comparison fails, and a
comparison that cannot see the change will not fail.

## Found broken, left alone

- **`reuseExistingServer` hides env changes.** `playwright.config.ts` reuses a
  running dev server outside CI, so a change to `webServer.env` — `FREEZE_NOW`,
  `SESSION_LIFETIME` — does not reach an already-started server and the symptom
  is a 419 or a 429 that no amount of re-running clears. Killing the process on
  port 8129 is the fix and nothing says so.
- **`e2e-setup.sh` seeds three extra tenants and a super admin** for the console
  screenshots. Every operator query is tenant-scoped so no operator spec can see
  them, but it is one more thing the e2e database contains that production does
  not. Carried over from phase 9.
- **`/dev/errors/{status}` is a new dev-only route.** Gated on
  `! app()->environment('production')`, the same gate and the same shape as
  `/dev/components`. It exists because an error page is by definition a state
  you cannot open when you want to, and one nobody can open is one nobody looks
  at.
- **The `table` rule no longer applies to `resources/views/mail/`.** Not an
  exemption but a scope decision: `ui/Table` is a Vue component and Outlook
  cannot run JavaScript, so a rule forbidding `<table>` there is a rule
  forbidding email. Every other rule still applies to the mail tree.
- **`BillingController::index` still hardcodes `'£39'`** rather than reading
  `config('billing.monthly_price_pence')`. Carried over from phase 8; the two
  agree today.
- **`customers.email` is still NOT NULL and unique per tenant**, so this product
  has no way to hold a client who is only a name and a phone number. Carried
  over from phase 8.
- **`check:design` cannot see a mockup in a subfolder.** `scripts/check-design-tokens.mjs`
  line 170 is `globSync('public/mockups/*.html')`, which is not recursive, so the
  gate that keeps a mockup's copied token block honest sees the three approved
  mockups and nothing below them. `public/mockups/directions/*.html` from the
  first marketing exploration has therefore never been checked, and neither is
  `public/mockups/marketing-v2/*/*.html` from the second. Widening it is one
  character — `public/mockups/**/*.html` — and nothing else in the script would
  need touching: `blockOf`, `declarations` and `normalise` are path-agnostic and
  the three `SCOPES` are what the new files carry. Left alone deliberately; the
  exploration reimplemented the same comparison in
  `public/mockups/marketing-v2/_verify/verify.mjs` and reports it clean, which is
  the evidence that widening the glob would not break the build.
- **The live marketing home page calls a drawing a screenshot.**
  `resources/views/marketing/home.blade.php` renders three flex rows of
  `border-rule` divs under `aria-label="Diary screenshot"` and captions the
  section *"This is the real product, not a drawing of it."* It is a drawing of
  it. Whatever phase 11 does with that section, the caption cannot survive it.
- **"Three no-shows a week is £135 gone, every week"**, same file. The
  arithmetic is right — 3 × £45 — and the framing is not. It calls revenue
  "gone" as though it were profit, and "a week" makes it £585 a month on a site
  selling a £39 product, which reads as either an enormous bargain or an
  exaggeration and the reader picks one. The fix is to derive the sum on the page
  from a stated slot price and a stated monthly volume, so a groomer can
  substitute her own numbers.
- **`marketing/pricing.blade.php` hardcodes `£39` and `£390`** rather than
  reading `config('billing.monthly_price_pence')` and `yearly_price_pence`. This
  is the same item already recorded above for `BillingController::index`; the
  marketing page is a second copy of the same two figures and nothing says so.
  Three places now agree by coincidence.
- **`.DS_Store` is not in `.gitignore`.** `public/mockups/.DS_Store` and
  `public/mockups/directions/.DS_Store` are both untracked, so any
  `git add public/mockups` commits them.

# Phase 11 — the marketing site

## The homepage argument, and why the ledger moved off it

Direction A led with a ledger: a competitor's per-booking fee against our £39.
It is a good table and it loses the sale. The fee only exceeds £39 above roughly
32 appointments a month, and the groomer this product is for does twenty — so
she reads a table we built, does the arithmetic we handed her, and correctly
concludes we are the more expensive option. A page that supplies the calculator
for its own rebuttal is worse than a page with no table on it.

So the homepage leads with recovered revenue instead:

> One cancelled slot refilled from the waitlist covers the month.

Three things recommend it. It is true at twenty appointments and at eighty,
because volume is not a term in it. It rests on our own number and a price the
groomer sets herself, so no competitor can invalidate it by changing a setting.
And it is the thing the free tools do not do — a free diary records the
cancellation; it does not sell the hour again.

The sum is built to be substituted into, not read off. `refill-sum.blade.php`
states one slot at the seeded £45, subtracts £39, and shows £6 left; the
footnote says to put your own slot price in the top line and that anything at or
above £39 covers the month. No monthly volume appears in the claim or the
working.

## The ledger, on /pricing, as a positioning argument

It answers one question — "why do you charge me when they are free" — and the
answer is not a number:

> Their software is paid for by your clients. Ours is paid for by you.

That survives the competitor halving their fee, which the cost comparison does
not. The final row of the table is `Who funds it → your clients | you`, in
words rather than figures, because that is the row the argument actually turns
on. The £1.25 is an illustration of the mechanism and the copy says so; if it
changed to £0.60 tomorrow the page would still read.

The competitor is not named anywhere on the surface, and `MarketingNavTest`
asserts it on all seven pages rather than only the one with the table.

## Every figure on the surface is read from the repo

`app/Support/MarketingFigures.php`. £39 came from `config('billing.monthly_price_pence')`,
30 days from `trial_days`, and £45, £10 and 60 minutes from the
`Full groom — medium dog` row of `config('verticals.php')` — the same row
`demo:seed` gives a new tenant. The £6 surplus is `slot - monthly`, computed,
not typed. The waitlist's "5 people" and "30 minutes" are
`config('booking.waitlist_offer_batch')` and `waitlist_offer_minutes`, the same
two values `WaitlistOfferer` reads when it actually sends the texts.

This clears the open item recorded twice in phase 10 — `marketing/pricing.blade.php`
hardcoding `£39` and `£390` alongside `BillingController::index` — for the two
marketing pages. `BillingController::index` still hardcodes `'£39'` and is still
recorded below.

## Open item — the seeded price of a medium full groom

`config/verticals.php` seeds `Full groom — medium dog` at `4500` (£45.00).

- **£45.00** — provenance: this repo, `config/verticals.php`, seeded to every
  new tenant by `demo:seed` and `TenantSeeder`. Verifiable by reading the file.
- **~£55** — provenance: stated as the 2026 UK average during phase 11 briefing.
  Not sourced. No survey, dataset or sample named. **UNVERIFIED**; what would
  verify it is a named 2026 UK grooming price survey with a sample size, or a
  count of published price lists we gathered ourselves.

Not changed in this phase, deliberately. It is the default price list every new
tenant starts from, so moving it is a product decision about what a groomer sees
on her first day, not a marketing one about what reads well — and the marketing
argument does not need it. The pages use £45 and never call it a market average;
`dog-grooming.blade.php` prints it as "the price list we set you up with", which
is exactly what it is.

If the £55 figure is ever sourced, the change is one integer in
`config/verticals.php` and the marketing pages follow automatically, because
none of them holds the number.

## `--page`, `--gutter` and `--arg` are in tokens.css now

Direction A declared them in a `[data-surface='marketing']` block inside the
mockup, which is the one place a layout width may not live. They are in
`tokens.css` beside the other three surfaces' chrome — `--rail`, `--booking-w`,
`--auth-col` — gated on `data-surface`, which is now set on all four surface
roots the same way `data-density` is.

**`--clock` was not promoted.** The brief lists four tokens; direction A
declares three. `--clock` is in `direction-b-thirty-seconds.html`, the rejected
file, where it sizes a countdown this site does not have. Promoting it would
have created a token with no consumer, which is what phase 2 deleted five
components for.

**Only marketing declares values.** `book` is a 440px column, and the operator
app and console are full-bleed beside the rail; none has a centred page frame.
The attribute is on all four roots so the gate is a real mechanism rather than a
marketing private, but inventing `--page` for three surfaces that would not read
it is the same dead-token mistake. If a second surface grows a page frame it
declares it there.

Not in `:root`, and for the reason `[data-scheme='mail-dark']` is not:
`check:design` asserts every mockup's `:root` carries every token `:root` has,
and a mockup of the diary has no business restating the marketing site's page
width.

Nothing shifted. Zero e2e baselines changed — `data-surface` is inert on the
three surfaces where no rule reads it, and no app CSS was touched.

## Found broken, left alone

- **`BillingController::index` still hardcodes `'£39'`.** Closed in the SMS
  metering pass. The page reads `BillingPrice`, which reads config (or the
  tenant override).
- **`config/billing.php` still configures an annual plan.**
  Closed in the SMS metering pass: the yearly keys are removed from config
  and the billing page. Marketing copy still mentions the old key; that
  file was not touched.
- **`customers.email` is still NOT NULL and unique per tenant.** Carried from
  phase 8. `/dog-grooming` describes a client list keyed on breed, size, coat
  and temperament and cannot mention that a client without an email cannot be
  stored at all.
- **`.DS_Store` is listed twice in `.gitignore`.** Harmless; both lines say the
  same thing. The phase 10 entry saying `.DS_Store` is *not* in `.gitignore` has
  since been fixed by somebody, and the fix was applied twice.

## Fixed in this phase, outside the pages themselves

- **`check:design` was checking zero mockups.** The phase 10 entry above records
  the glob as `public/mockups/*.html` and non-recursive. The mockups have since
  moved to `.design/mockups/`, so the glob matched *nothing at all* and the gate
  that keeps a mockup's token block honest had gone silently green. Repointed to
  `.design/mockups/**/*.html`, which both fixes the path and makes it recursive.
  All five mockups were verified clean against `tokens.css` before the change
  and the gate reports five blocks checked after it. This was already scoped as
  safe by the phase 10 entry, and leaving it broken would have meant promoting
  three tokens with the only check on that promotion switched off.
- **The `table` rule no longer applies to `resources/views/marketing/`.** Same
  scope decision the mail tree already carries, for the same reason: `ui/Table`
  is a Vue component and this surface is deliberately Blade with no JavaScript.
  Three tables on it are genuinely tabular — the refill sum, the ledger and the
  grooming price list — and a rule forbidding `<table>` there is a rule
  forbidding a table of prices. Every other rule still applies.
- **The marketing footer floated mid-page on short documents.** `/about`,
  `/contact`, `/privacy` and `/terms` are 370px of content, so the footer sat
  where the content stopped with 350px of bare paper underneath it. The body is
  a full-height flex column now with `<main>` taking the slack, which is what
  `public-shell.blade.php` already did. Found by looking at the 900px-tall
  screenshots, not by any assertion — the heights table showed four pages at
  exactly 900 at all five widths, which is what "shorter than the viewport"
  looks like in a number.

# The test suite is MySQL now

Local, test and production are all MySQL. `phpunit.xml` no longer declares
`DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`. SQLite made three things
untestable:

- `lockForUpdate()` is a documented no-op, so no test exercised the booking
  lock. Two customers could double-book and the suite stayed green.
- FK cascades behave differently, so nothing could catch a profile deletion
  wiping bookings.
- `VerifyCsrfToken` short-circuits under `runningUnitTests()`, so CSRF was
  never verified. That last one is still true — `APP_ENV=testing` still skips
  the token check. Switching the engine does not change it. Recorded, not
  fixed.

The test database is `appoint_manager_test`, forced in `phpunit.xml` so a
developer with `DB_DATABASE=appoint_manager` in their shell cannot
`migrate:fresh` the salon they are working on. How to bring it up is in
`DEPLOY.md`. Parallel Pest workers then create `appoint_manager_test_test_N`
via Laravel's ParallelTesting.

`QUEUE_CONNECTION=sync` is unchanged and that is a separate task. Every
notification test currently asserts side effects that only happen because the
job ran inside the HTTP request. Flipping it to `database` or `redis` without
rewriting those tests would turn a hundred greens red for the wrong reason,
and would not by itself catch fail-open scoping in workers — that needs a
real worker process with no tenant context, which is AUDIT C9 and is already
covered in-process by `ScopeFailClosedTest`. Do not silently change it.

## Found broken, left alone

Closed or moved in phase 12, section 5. The living list is **Left permanently**
at the end of this file, so this heading stops growing as a backlog.

## What the concurrency tests now do

`tests/Support/Concurrent` commits RefreshDatabase's wrapping transaction,
forks two PHP processes (each with its own PDO, pointed at the same
database the parent is using — including a ParallelTesting suffix), releases
them from a barrier, and re-opens a transaction so the trait's rollback
still has something to roll back.

Fail-first, with `lockForUpdate()` removed from the old bookings window:

- two transactions: both returned `ok: true` with `booking_id` 1 and 2
- two public requests: `[201, 201]`
- two waitlist claims: `[200, 200]`

Restored against the empty-window gap lock, the same three failed on
deadlock 500 instead of double-booking. That is fixed: the staff `users`
row is locked instead. See "Booking lock is the staff row" below.
`TenantIsolationTest` could not fail-first: `runningUnitTests()` is no
longer in `TenantScope`. The test now flips `env` to `local` so a
reintroduced console exemption cannot hide behind `APP_ENV=testing`.
`ScopeFailClosedTest` already covered this more thoroughly.

## C1, C2 and C10 were already closed

Re-read 2026-08-30 against the code. AUDIT.md described a production fake
Stripe bind, a webhook that confirmed any `metadata.booking_id`, and an
unauthenticated `customer-match` URL. None of those paths exist. The
existing tests in `tests/Feature/Security/` already demonstrate the holes
are shut. No production code was changed. AUDIT.md is updated to say so.

## Booking lock is the staff row

`SELECT … FOR UPDATE` on overlapping bookings gap-locks an empty index
range. Two concurrent first-of-the-day inserts into that gap deadlock
(`SQLSTATE 40001`): one booking remains, the loser got a 500.

The lock is now `users.id` for that staff member (scoped by `tenant_id`).
The row exists, so InnoDB takes a row lock. The loser waits, then
`assertSlotOpen()` throws `SlotUnavailableException` — the same 409 /
"That time is no longer available." the sequential re-check already
produces. Waitlist claims wrap that as `OfferUnavailableException::taken()`
(also 409). The operator diary catches the same exception and returns
validation errors on `starts_at`.

A leftover 40001 is mapped to that same exception and reported, so a
customer never sees a 500. There is no retry. A retry would hide a
still-wrong lock under load; the staff row should not deadlock. One
injected-deadlock test keeps the 409 body from regressing to 500.

Single-staff high volume: every booking for that person serialises on one
row. The transaction is short — lock, availability, insert — and mail/SMS
already run after commit. Different staff do not contend. A slot-level
`GET_LOCK` would be finer but is connection-scoped and does not roll back
with the transaction. Not worth it until a salon actually queues on one
stylist.

## Automatic rebooking

Due interval, highest wins: checkout on this appointment
(`bookings.rebook_interval_days`), then the subject's own
(`subjects.rebook_interval_days`), then the service default
(`services.suggested_interval_days`, seeded from
`config/verticals.php` `rebook_interval` `{value, unit}`). Units
convert to days in `VerticalInterval` so a dentist vertical can say
months without a code change. A checkout value is written onto the
subject so the groomer teaches the system by using it.

The overdue list is subjects whose last completed/confirmed visit is
due today or earlier, with no future booking, not snoozed, not stopped,
and not marked contacted in the last 14 days. Sorted by how overdue.
The money figure is the sum of each subject's usual service price —
the current price of the service on their last visit, not an average.

Sending is off in `settings.rebooking.messages_enabled` for every
tenant. Turning it on requires a dry run — who, the exact message,
how many — and an explicit confirm. A POST to enable without that
preview is refused. `rebooking:send` then runs daily at 09:00.

The checkout override is on the operator diary, not the public booking
page. The groomer teaches the system; the client does not pick their
own interval. Leave "Come back in" on The usual and nothing is written
to the subject.

## SMS metering

Config keys in `config/billing.php`, defaults:

- `sms_included` 200
- `sms_topup_size` 200
- `sms_topup_price_pence` 800
- `sms_hard_ceiling` 1000
- `sms_warning_thresholds` `[80, 100]`
- `owner_alert_email` from `BILLING_OWNER_EMAIL`, else `MAIL_FROM_ADDRESS`

`yearly_price_pence` is 29000 (£290) and `yearly_price_id` reads
`STRIPE_PRICE_YEARLY`. That is ten months at the £29 list price — the
"2 months free" label. Checkout still opens the monthly Stripe price
unless it is asked for yearly. The live `/pricing` page sells both
intervals from those keys.

**Top-up rollover.** Purchased top-ups and granted credit roll over
across billing cycles. The included 200 resets with the cycle. They paid
£8 for 200 messages; those are prepaid inventory, not a monthly perk.
Resetting them would charge twice. The included pack is the subscription
and resets so unused included SMS do not accumulate forever.

**Price truth.** `tenants.monthly_price_override_pence` is what we charge
that salon. `config('billing.monthly_price_pence')` is the list price —
register, marketing, the default checkout. The two may disagree. That is
the founding-rate case. Checkout reads the tenant.

Allowance is consumed in `SendSms` after the provider accepts the
message. A failed send does not consume. Hitting the included allowance
stops SMS and leaves email, the overdue list, and the phone. Hitting the
hard ceiling stops SMS the same way and cannot be lifted by a top-up;
the platform owner is emailed. The kill switch is `sms_killed_at` and is
checked on the next send, including a job already queued.

At 80% the operator sees a banner and gets an email. At 100% of included,
SMS stops unless prepaid remains; banner and email say so. At the ceiling,
SMS stops, a top-up will not restart it, and the owner is alerted.

# Rebooking safety

Everything here is about the difference between a seeded database and two
hundred real dog owners with real phones.

## The duplicate rule, and why it is a unique index

`rebooking:send` used to guard against duplicates with
`alreadySentThisCycle()`: a `SELECT` on `messages` for a `rebook_due` row in
the last fourteen days, followed by a send. That is a read, a gap, and a write.
The gap is where a second scheduled run, a manual trigger, two queue workers
and a retry after a crash all get their duplicate, and none of them would look
like a bug — they would look like a groomer's client being texted every morning
for a fortnight.

So the rule is now a table:

    rebook_sends
      unique (tenant_id, subject_id, due_on, attempt)

A row is a **claim**, inserted before the message is queued. Two callers race,
one gets a row, the other gets SQLSTATE 23000 and is refused. There is no
window, and the enforcement does not depend on any line of the job being
correct. `RebookAttempts::claim()` catches 23000 and returns null; the job
treats null as "somebody else has this one" and moves on.

**The cycle key is `due_on`, not a timestamp and not a counter.** `due_on` is
the date the subject fell due — last visit plus interval. Booking moves the last
visit, which moves the due date, which is a new cycle. Nothing else can produce
one, so "they booked, chase them again next time" needs no code at all. There is
no clock arithmetic in the uniqueness rule.

Proven three ways in `RebookingSafetyTest`: the job run on three consecutive
days sends one message; the job run twice in the same minute sends one message;
and a hand-written `INSERT` straight at the table, bypassing every line of
application logic, throws `UniqueConstraintViolationException`.

## Chased, did not book, still overdue six weeks later

**One follow-up after a gap, then silence.** `max_per_cycle` is 2 and
`follow_up_gap_days` is 21. Day one: the chase. Day twenty-two: the follow-up.
After that nothing, ever, for that due cycle — a client who has been asked twice
and has not booked is a phone call, not a third text. A fourth, fifth and sixth
run at day 42, 63 and 84 were asserted to send nothing.

They stay on the overdue list, which is the point. The list is useful whether or
not messages are on: it has the phone number, the due date and the money. What
changes is that the list is now the *only* thing that will reach them, and the
dry run says so — `attempts_used`.

## STOP

`customers.sms_opted_out_at`, set only through `SmsConsent` and deliberately not
fillable. A consent flag a mass-assigned customer form could clear is not a
consent flag.

**Per tenant falls out of the schema.** `customers` is already per tenant, so a
client of two salons is two rows. Nothing has to remember the rule.

**Marketing only.** `MessageType::isMarketing()` is true for `RebookDue` and
false for everything else. A confirmation, a reminder, a cancellation and a
waitlist offer are service messages about an appointment the customer made, and
somebody who replied STOP to a marketing text has not asked to stop being told
their dog's appointment moved. Suppressing those would put a person outside a
locked salon door. Asserted: a confirmation still sends to an opted-out client.

**Which salon is a reply to?** Inbound SMS arrives on one platform number shared
by every tenant, so the webhook payload cannot say. `SmsConsent::resolve()` uses
the most recent outbound SMS to that number: a STOP is a reply to the last thing
that arrived. The alternative — opting the number out of every tenant that holds
it — was rejected, because it is the wrong answer to "opt-out is per tenant" and
it lets one salon's client silence another salon's messages. If we have never
texted the number there is nothing to opt out of and the endpoint says so
rather than guessing. Asserted with the same phone number at two salons.

`STOP STOPALL UNSUBSCRIBE CANCEL END QUIT` opt out; `START UNSTOP` opt back in.
Case-insensitive, trimmed, and with surrounding punctuation stripped, because
"STOP." and "stop!" are the same intent and refusing them over a full stop
would be indefensible. Twilio suppresses its own standard set at the number
level whether or not this endpoint exists; we handle them too, because a
message Twilio silently drops is one we have still counted, still logged as
sent, and still shown the salon as a chase that happened.

**`/twilio/inbound` verifies `X-Twilio-Signature`.** AUDIT H7 recorded
`/twilio/status` as unverified, which was survivable while the endpoint only
moved a row from `sent` to `delivered`. It stops being survivable when an
endpoint can set a consent flag: an unauthenticated caller who knows a phone
number could opt a salon's client out of messages that salon is paying for.
`VerifyTwilioSignature` now covers both, and skips when no `TWILIO_TOKEN` is
configured — which is every local and test environment, and is what keeps it
from being a wall in front of the suite.

## The hour, in the salon's timezone

`SendWindow`, defaulting to 09:00–18:00 on weekdays, evaluated in
`tenants.timezone`. Per-tenant override at
`settings.rebooking.send_window`.

**`rebooking:send` is hourly now, not daily at 09:00.** A single daily run is
inside exactly one timezone's window: a salon in Sydney would never have been
sent for at all. Hourly is only safe *because* the duplicate rule is a unique
index — twenty-four runs a day produce one message per subject per cycle, and
would do so if it ran every minute. This is the clearest illustration of why
that rule had to move to the data layer.

Outside the window nothing is claimed and nothing is dropped. The subject is
still overdue at nine tomorrow morning. Asserted with a Sydney tenant: at 10:00
UTC (20:00 there) nothing sends; at 23:30 UTC (09:30 there) it does. A London
tenant at the same first instant is at 11:00 and is sent for.

## Segments, not messages

**Allowance decrements by segment.** `sms_cycle_used` counts what the carrier
bills. A message over 160 GSM-7 characters is two segments, and one character
outside GSM 03.38 — a curly apostrophe from Word, an emoji, the ë in Zoë —
converts the whole message to UCS-2 and drops the limit to 70.

Counting messages was the alternative and it is wrong twice over. It makes a
salon's 200 quietly cost us 400, and it makes the hard ceiling — which exists to
bound *spend* — bound something that is not spend. `canSend()` also takes the
segment count of the message about to go out, so a two-segment message cannot be
waved through on a one-segment remainder.

`App\Support\SmsSegments` does the counting: GSM-7 at 160/153, UCS-2 at 70/67,
the nine extension characters (`^{}\[~]|€`) charged two septets each, and UCS-2
measured in UTF-16 code units so an emoji costs two. Eleven unit tests, including
the 160/161 and 69/70 boundaries.

Sanitising is limited to punctuation: curly quotes to straight, the dashes and
the ellipsis, and the four space characters that are not spaces. **Letters are
never touched.** Stripping the accent from a customer's name to save a penny is
not our decision to make; the cost is reported instead.

### The truncation bug this uncovered

`Notifier::fitSms()` was `Str::limit($body, 160, '')`, and every one of these
messages ends in a URL. A salon with a long name produced a confirmation text
with the booking link sliced in half — silently, with nothing to catch it. The
rebooking body had the same shape and the same bug.

`SmsSegments::fit()` replaces it: the caller names the one part that may be
shortened — the salon's own name, the only unbounded string in any of these
bodies — and a callable puts the message back together around it. The link and
the opt-out notice survive. With `max_segments` at 3 a real salon name never
reaches the guard at all; it exists to bound a pathological case, not to format.
Asserted: a confirmation for a 184-character salon name still contains the
booking token.

### The opt-out notice is in the body

`" Reply STOP to opt out."`, appended by `RebookMessenger::body()` and therefore
counted in the segment budget. A message that fits in 160 characters until the
legally required sentence is added is a two-segment message, and we would rather
know that before sending two hundred of them. The dry run shows the exact
string, character count and segment count per message, and warns when any
message exceeds one segment.

## Failure, and the truth about what was sent

- **Provider rejection consumes nothing.** Unchanged: `SendSms` consumes after
  the gateway returns a SID.
- **A rejected send releases its claim.** `RebookAttempts::release()` deletes
  the claim row and clears `rebook_contacted_at`, so tomorrow's run retries
  rather than hiding the subject behind a contact that never happened. Deleting
  is deliberate — the claim's job is to prevent a duplicate *delivery*, and
  nothing was delivered. The attempt is not lost: it is in `messages` with
  status `failed`, which is what the salon sees.
- **Retries are bounded.** `subjects.rebook_failed_sends`, blocked at
  `max_send_failures` (3), flagged on the list as "check number". A permanently
  invalid number stops being dialled.
- **Correcting the number clears the flag.** On `Customer`'s `updated` event
  rather than in `CustomerController`, because the number is also editable from
  the import path and from tinker, and a flag that only clears down one route is
  a flag that gets stuck.
- **Billing on accept is kept, and made visible.** A later `failed` or
  `undelivered` callback is not refunded — Twilio bills us on accept, so
  refunding would mean absorbing a cost we really incurred. What changes is that
  `messages.provider_error` records what the carrier said, the overdue page has
  a send log that shows it, and an undelivered rebooking chase counts towards the
  failure bound. "Unreachable destination handset" on the screen is the
  difference between shrugging and correcting a digit.

### `messages.subject_id`, and why the claim is not found by message id

The obvious link from a failed message back to its claim is
`rebook_sends.message_id`, and it does not work. The gateway is called from
inside the queued job, and on the `sync` driver — the whole test suite, and any
deployment without a worker — a provider rejection throws before the caller has
had a chance to write the id onto the claim. So the link that has to survive a
throw is the one the message itself carries. `messages.subject_id` is that link.
`rebook_sends.message_id` is kept for the audit trail and is best effort.

It earns its place twice: a client with two dogs receives two chases, and a send
log naming only the client cannot say which one bounced.

### `succeeded()` is not called on provider accept

Twilio accepting a message says nothing about whether a handset received it.
Clearing `rebook_failed_sends` on accept would mean a permanently dead number
resets its own history every cycle and is dialled forever. Only a `delivered`
callback clears it.

## The ceiling: 600 → 1000

Three top-ups was a wall a busy salon hit and had to telephone us about, which
is a poor first impression of a product sold on saving her work. 1000 is five
times the included pack and still bounds the runaway-loop case the ceiling
exists for: a loop reaches it in minutes and it is £40 of spend rather than
£400. Everything else about the ceiling is as built — a top-up will not lift it,
only super admin can, and the platform owner is emailed.

## The trial allowance is a policy now

It was emergent. A tenant with no Stripe invoice has no cycle-reset event, so
`maybeResetCycle()` reads the month off `sms_cycle_started_at` — and a sixty-day
trial therefore received two included packs, which nothing said.

The behaviour is kept. A long trial that runs out of texts in week five stops
demonstrating the feature it is there to sell. But it is now
`billing.sms_trial_included` (null = same as `sms_included`) and
`billing.sms_trial_resets_monthly` (true), so changing it means changing two
keys rather than reasoning about invoice dates.

## New config keys

`config/rebooking.php`, all of it new:

| Key | Default | Controls |
|---|---|---|
| `contacted_window_days` | 14 | How long a contacted subject stays off the **list** |
| `attempts.max_per_cycle` | 2 | Messages per subject per due cycle |
| `attempts.follow_up_gap_days` | 21 | Days between chase and follow-up |
| `attempts.max_send_failures` | 3 | Rejections before a number is flagged and dropped |
| `send_window.start` | `09:00` | Earliest send, tenant's timezone |
| `send_window.end` | `18:00` | Latest send, tenant's timezone |
| `send_window.days` | `[1,2,3,4,5]` | ISO weekdays sending is allowed |
| `message.body` | `:salon: :subject is due :due. Book: :url` | The chase |
| `message.opt_out_suffix` | ` Reply STOP to opt out.` | Appended, and counted |
| `message.warn_above_segments` | 1 | Dry-run warning threshold |
| `message.max_segments` | 3 | Runaway guard, not a formatting rule |
| `opt_out_keywords` | stop, stopall, unsubscribe, cancel, end, quit | Inbound opt-out |
| `opt_in_keywords` | start, unstop | Inbound opt-in |
| `opt_out_reply` / `opt_in_reply` | `''` | Empty: Twilio already acknowledges its own keywords |
| `send_log_rows` | 20 | Rows on the overdue page's send log |

`config/billing.php`: `sms_hard_ceiling` 600 → **1000**; new
`sms_trial_included` (null) and `sms_trial_resets_monthly` (true).

`config/services.php`: new `twilio.verify_signature` (true).

---

# Phase 12, section 2 — a path to test rebooking on a real phone

## Why a second seeder rather than extending `demo:seed`

`demo:seed` produces a diary, which means bookings in the future. It is the
right shape for demonstrating the booking page and the wrong shape for
rebooking, where the entire feature is a function of the *past*. Nothing
`demo:seed` writes is overdue, so the overdue list seeded empty and the feature
could not be looked at, let alone tested against a handset.

Extending it was considered and rejected: the two need opposite data, and a
single command that produced both would have to be told which it was doing —
which is two commands with a flag on top.

`demo:rebooking` is therefore additive. It does not touch `demo:seed`, and it
can refill a tenant `demo:seed` created (`--slug=`).

## Twenty-two clients, written out rather than generated

`fake()` would have been shorter and is wrong here. The value of this seed is
that the overdue list has a *shape* — four not due, six a few days over, five in
the middle, three badly over — and a random spread produces that only sometimes.
A demo that is convincing four runs in five is not a demo.

So the list is a constant, and the fourth column is days since the last visit.
Whether a row is overdue is that minus the service's own interval, which is read
from `config/verticals.php` along with the price. Nothing here invents a price
or an interval.

Two of the names are load-bearing. **Zoë** exists because one accented character
converts a 112-character message to UCS-2 and makes it two segments; that is the
warning in the dry run, and it needs to fire on the seeded data rather than only
in a test. **Scout** is the subject on the real number, overdue by a fortnight so
it sits in the middle of the list rather than at either end.

## Every seeded number is on Ofcom's reserved range

`+447700900000`–`900999` is reserved for drama and documentation. Nothing seeded
can ring a stranger's phone.

This is not decoration. The command that follows is `rebooking:send --force
--ignore-window`, and a plausible-looking fake number is one mistyped flag away
from texting somebody's grandmother. A demo whose fake numbers cannot ring is
the only version of this that is safe to leave in the repository.

The one real number comes from `--phone` or `REBOOKING_DEMO_PHONE` and the
command **refuses to run without it**. A silent fallback would have produced a
seed with no way to receive the text, which is the one thing it is for.

## Idempotency, and what it resets

Keyed on the tenant slug, a client's derived email, a subject's name, and a
visit's exact start instant. A second run updates. Verified by asserting equal
row counts across two runs, not by inspection.

It also *resets* the flags it owns — snooze, stop, failure count, block, and
opt-out — then re-applies the three deliberate states. This matters more than
usual because the command exists to be re-run while experimenting, and a STOP
you sent yourself last time would otherwise silently suppress this run's sends.
That is a confusing half-hour.

Claims in `rebook_sends` are **not** reset, deliberately: the once-per-cycle rule
is the thing being tested, and a seeder that quietly cleared it would hide the
feature. DEPLOY.md gives the one-line delete for when you do want a clean slate.

## `--force`, and why it requires `--subject`

Sending is off until the operator previews a dry run — correctly, and that gate
also blocks the one deliberate test send that has to happen before any customer
sees this.

`--force` bypasses it and is **refused without `--subject`**. Forcing one named
subject is the entire point; forcing a client base is the failure mode the gate
exists to prevent. The guard is in the command, and the service throws if asked
to bypass the gate with an empty subject list, so it cannot be reached from a
second caller either.

`RebookMessenger::sendDue()` takes `$ignoreEnabledGate` and throws
`InvalidArgumentException` when it is true with no subject whitelist.

## Two footguns the command now warns about

Neither is new, and both make a manual test look like a broken feature:

- `SMS_DRIVER=log` is the default, so a send writes to a log file and no text
  arrives.
- `QUEUE_CONNECTION=database` is what `.env` ships, and `SendSms` is queued — so
  the command reports success and the message sits in `jobs` until a worker
  starts. This one cost time during verification: `messages.status` was `queued`
  and the row looked wrong.

The seeder prints a warning for each. DEPLOY.md documents both, and the queue
one is called out as the commonest reason nothing appears to happen.

## Tests

`tests/Feature/Launch/RebookingDemoSeedTest.php`, eight cases:

| Test | Proves |
|---|---|
| genuine variety | ≥20 subjects, the overdue count is strictly between 8 and all of them, and both `days_overdue <= 5` and `>= 40` are non-empty |
| price list from config | Every service matches `config/verticals.php` by name and amount |
| the number given | Exactly one customer on it; every other number on `+447700900` |
| every state | One snoozed, one stopped, one opted out — and the opted-out one still on the list with its marker |
| run twice | Equal counts for tenants, customers, subjects, bookings, services |
| no phone number | Exits 1 and writes no tenant |
| outside local | Refused |
| one message | With sending **off**, `--subject --force` produces exactly one SMS, to the seeded number, carrying that subject's id |

# Phase 12, section 3 — the product name

The name people read is `config('product.name')`, default `DiaryDesk`, overridable
as `PRODUCT_NAME`. It is deliberately not `app.name`.

`app.name` slugs into the cache prefix, the Redis prefix, and the three session
cookie names via `Surface`. Renaming the product through that key would sign
every operator out, cold-start the cache, and orphan the Horizon prefix. The
repository, the database and the composer package stay as they are until they
are renamed together with the domain.

SMS bodies do not carry the product name. They open with the salon name. A
confirmation, a reminder and a rebooking chase are from the salon to a client
who has never heard of us; putting our name on them would spend a segment
telling the wrong person who we are.

The PWA manifest is composed by `SurfaceRoutes` rather than served as a static
file, because a static file is the one place a rename silently misses.

# Phase 12, section 4 — two marketing flaws

## The masthead at 375

Pricing and Dog grooming were `off-phone`: hidden below 768, reachable only
from the footer. A groomer handed this URL in person opens it on a phone, and
the two routes that tell her what it costs and whether it is for her were not
in the top of the page.

Four links do not get a hamburger. The masthead is two lines at 375 — wordmark,
then the four routes — hairlines and type. The footer still carries the same
two links because About, Contact, Privacy and Terms have nowhere else to live.

## No invented salon

The quoted waitlist texts used "Willow Street Grooming" and labelled it
illustrative. A name on a marketing page that is not a customer is invented.
The prefix is now described (`the salon’s name`) rather than substituted. The
invariant bodies are still the strings in `Notifier`.

# Phase 12, section 5 — recorded debt

Worked through the growing "Found broken, left alone" list. Closed what this
pass could close. Survivors live under **Left permanently** so the heading
stops looking like a backlog.

## Closed here

- **`.DS_Store` listed twice in `.gitignore`.** One line remains.
- **`composer.json` `post-create-project-cmd` touched `database/database.sqlite`.**
  Removed. We are MySQL everywhere; that file is not part of the product.
- **Leftover `kestrel` database.** Empty (zero tables). Dropped on this
  machine. The `DROP DATABASE` stays in the rename section above — `DEPLOY.md`
  points here, because the name check forbids the old name everywhere else.
- **MySQL version.** 8.4 is authoritative — docker-compose and production.
  This laptop is 9.5; that is a local deviation, recorded in `DEPLOY.md`, not
  a second target. What breaks if they diverge: reserved words, auth plugin,
  JSON / generated-column differences, SQL that 9 accepts and 8 rejects.
  InnoDB gap locks have matched in practice; that is not permission to treat
  them as interchangeable.
- **`customers.email` is nullable.** A walk-in is a name and often a phone
  number. The unique index still holds for addresses that exist; MySQL allows
  more than one NULL, so two clients without an email are two rows. Diary and
  onboarding no longer require an address. Public booking and CSV import still
  do — an online booker needs the manage link, and an import row without an
  email is a different path. `Notifier` skips customer email when there is
  nowhere to send it. Empty string is written as null, never as `''`, because
  `''` would still collide on the unique index.
- **`TenantAccentTest` payload is `x);url(//evil` (14 characters).** Fits
  `varchar(20)`. The view still must not contain `evil`.

## CSRF, assessed and left

`ValidateCsrfToken` short-circuits when `APP_ENV=testing`. Enabling it
suite-wide would 419 every mutating HTTP test that does not send `_token`.
`FakeStripeGateway` also binds on that env, so flipping `APP_ENV` is not a
one-line fix. ErrorPageTest already leaves testing and POSTs without a token
to prove the 419 page. AuthenticationTest now does the same on `/login`.
That is the canary. Suite-wide coverage would mean a `_token` (or `from()`)
on every POST, PATCH and DELETE in the suite, plus keeping the Stripe fake
bound some other way.

# Left permanently

Items that are not a backlog. Each has a one-line reason and what would close
it. Do not re-open these as "found broken" unless the reason changes.

- **CSRF is not verified on the rest of the suite.** Reason: `APP_ENV=testing`
  short-circuits `ValidateCsrfToken`; enabling it turns every mutating HTTP
  test red. What would close it: send `_token` on every POST/PATCH/DELETE and
  bind the Stripe fake without relying on `testing`. Canaries exist
  (`ErrorPageTest`, `AuthenticationTest`).
- **This laptop runs MySQL 9.5 for day-to-day.** Reason: brew/pkg on this
  machine, not a product choice. Authoritative is 8.4. What closes the
  verification gap: `./scripts/test-mysql84.sh` (8.4 on 33084, serial then
  parallel). Day-to-day `./vendor/bin/pest` still hits whatever is on 3306.
- **`QUEUE_CONNECTION=sync` in the suite.** Reason: every notification test
  asserts side effects that only happen because the job ran inside the
  request. What would close it: rewrite those tests against a real queue, plus
  a worker process with no tenant context (AUDIT C9). Recorded since phase 11.
  Not this pass.

# Phase 12, section 6 — full verification

## Gates

| Gate | Result |
|---|---|
| `npm run check` | pass (design, contrast, components, name, vitest 115, pint) |
| `npx vue-tsc --noEmit` | pass |
| `./vendor/bin/pint --test` | pass |
| Pest serial | **643 passed** (2837 assertions), 45.25s |
| Pest `--parallel` | **643 passed** (2837 assertions), 20.56s, 8 processes. Agrees. |
| `npm run test:e2e` | **69 passed**. Dashboard baselines regenerated: the overdue card from rebooking was missing from the old shots. |
| Lighthouse mobile, 5 runs each | home and pricing both **99 / 100 / 100 / 100** every run. `php artisan serve` flatters performance; 99 is not what a real edge will score. |

`check:name` first failed on the leftover-schema note I had put in `DEPLOY.md`. The old name is only allowed in `DECISIONS.md`. The DROP stays in the rename section; DEPLOY points there.

## Manual walk

Login was the thing a previous pass could not finish. The cause is host, not credentials. `.env` has `APP_URL=http://localhost:8000`. Ziggy builds `route('login')` on that host. Opening `http://127.0.0.1:8000/login` posts cross-origin; the session cookie never rides, the page does not move, and there is no error callout. `http://localhost:8000/login` with `owner@rebooking-demo.test` / `password` lands on the diary. Super admin is `admin@gmail.com` / `admin@1234` at `/admin/login`. `node scripts/walkthrough.mjs` is the click-through.

What I actually opened:

- **Login** at 375 and 1280. Wordmark is DiaryDesk. Two columns at 1280, one at 375. Fine.
- **Diary** for Sunday 30 August. Empty grid — `demo:rebooking` seeds history, not today. Two staff columns (Demo Groomer, Demo Owner). Overdue 16 on the rail. Fine, but a first-time opener will think the product has no bookings.
- **New booking** drawer. Email is not required. **Come back in** set to 4 weeks. The interval control is the last field, after dog name, which is easy to miss.
- **Overdue** at 375 and 1280. Sixteen dogs, £560. Nala marked **no texts**. Scout labelled **Your own mobile**. Gus under Stopped with Start chasing again. One queued text in the send log. Preview messages is the first thing on the page. At 375 the list is cards, not a table; that is readable. I did not get a screenshot of the dry-run bodies — the script waited for the word "segment" and the page says "texts" unless a message is over one part. Snooze and stop were not clicked on this pass.
- **Billing, super admin controls, send log** — not clicked in the browser this pass. The pages exist; `/billing` shows price, `used of included`, and the top-up button. The usage figure is not in the rail. It appears on Billing, and as a banner only after 80% or a stop. At zero used, there is nothing to "see" on the diary.
- **Public booking** `/book/rebooking-demo` returned 200, title `Rebooking Demo Salon — book in London, E8 3AA`.
- **Deposit with a Stripe test card** — not done. `STRIPE_KEY` and `STRIPE_SECRET` are empty, and the demo tenant has no connected account. The booking page cannot reach a card.

What looked wrong or confusing, plainly:

- Empty diary on a Sunday after a rebooking seed. The overdue list is where the work is; the diary looks dead.
- Login on `127.0.0.1` fails silently. DEPLOY already says localhost; the failure mode still needs to be named, because it is how this walk died last time.
- No usage number in the chrome until you are near the limit. The brief asked to see the counter; it lives on `/billing` only.
- I did not finish snooze, stop, dry-run bodies, admin kill/credit/trial, or a real card. Those are in the script. They are not a substitute for having watched them.
