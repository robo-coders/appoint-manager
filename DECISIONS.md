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
