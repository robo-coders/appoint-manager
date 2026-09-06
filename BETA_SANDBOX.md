# Beta sandbox

A beta salon is a real tenant with training wheels: it can never take a real
payment, and its owner has three buttons that fill, advance and empty their own
shop. It exists so a beta tester can answer "what happens when somebody does not
turn up" without waiting a week or inventing a customer by hand.

Everything the feature owns is named or grouped under **`BetaSandbox`** and
**`Sandbox`**, plus **`is_beta`** and **`sandbox_state`**. This file is the
checklist a future "delete the beta sandbox" prompt works from: it is complete,
and every integration point outside the namespace is named with the exact edit
that reverses it.

---

## 1. The removal checklist

### Delete outright

| Path | What it is |
|---|---|
| `app/BetaSandbox/BetaSandbox.php` | `enabled()` / `guard()` — the server-side gate |
| `app/BetaSandbox/SandboxMute.php` | the outbound-message mute |
| `app/BetaSandbox/StripeTestMode.php` | the Stripe test-mode rule |
| `app/BetaSandbox/SandboxTables.php` | which tables are transactional, which are shiftable |
| `app/BetaSandbox/SampleData.php` | "Load sample data" |
| `app/BetaSandbox/FastForward.php` | "Skip 1 day" / "Skip 1 week" |
| `app/BetaSandbox/SandboxReset.php` | "Reset my shop" |
| `app/BetaSandbox/SandboxNotReady.php` | the one refusal an owner can act on |
| `app/Http/Controllers/BetaSandbox/SandboxController.php` | the original four endpoints |
| `app/Sandbox/` | toolkit controllers and wrappers (jump, no-show, waitlist, outbox, reminders, flaky network) |
| `routes/beta-sandbox.php` | the original four routes |
| `routes/sandbox.php` | the toolkit routes |
| `resources/js/Components/BetaSandbox/Banner.vue` | the global bar |
| `resources/js/Components/Sandbox/` | status strip and SMS outbox |
| `resources/js/Pages/Settings/Sandbox/Index.vue` | Settings → Beta sandbox |
| `tests/Feature/BetaSandbox/BetaBannerTest.php` | banner shows / never shows |
| `tests/Feature/BetaSandbox/BetaStripeTestModeTest.php` | test-mode guard |
| `tests/Feature/BetaSandbox/SampleDataTest.php` | shape, idempotence, silence |
| `tests/Feature/BetaSandbox/FastForwardTest.php` | shift + automation + scope |
| `tests/Feature/BetaSandbox/SandboxResetTest.php` | wipes, preserves, rolls back |
| `tests/Feature/BetaSandbox/SandboxGuardsTest.php` | 404s, tampering, impersonation |
| `tests/Feature/BetaSandbox/SuperAdminBetaFlagTest.php` | the console switch |
| `tests/Feature/BetaSandbox/ToolkitTest.php` | sizes, jump, no-show, waitlist, outbox, reminders, flaky |
| `BETA_SANDBOX.md` | this file |

### Reverse by hand

| File | The edit |
|---|---|
| `database/migrations/2026_09_06_100000_add_is_beta_to_tenants_table.php` | new migration dropping `tenants.is_beta`; delete this one only if it has never run anywhere |
| `database/migrations/2026_09_06_120000_add_sandbox_state_to_tenants_table.php` | new migration dropping `tenants.sandbox_state`; delete this one only if it has never run anywhere |
| `app/Models/Tenant.php` | drop `'is_beta'` and `'sandbox_state'` from `$fillable` and from `casts()` (both marked with a `BetaSandbox` comment) |
| `app/Services/Stripe/StripeConnectGateway.php` | **integration point 1** — `client()` back to `new StripeClient(config('services.stripe.secret'))` with no argument; delete `clientForAccount()`; drop the `$tenant` / `$accountId` arguments at its seven call sites; drop the `StripeTestMode` import |
| `app/Services/Notifications/Notifier.php` | **integration point 2** — delete `sandboxMuted()`, its five `if (! $this->sandboxMuted())` guards, the two `$muted ? MessageStatus::Sent : MessageStatus::Queued` ternaries (back to `MessageStatus::Queued`), and the `SandboxMute` import |
| `app/Services/Waitlist/WaitlistOfferer.php` | **integration point 3** — `expireAndContinue()` loses its optional `?int $tenantId` and the two `when()` clauses. Tenant-agnostic and safe to leave in place |
| `config/services.php` | delete `stripe.test_secret` |
| `.env.example` | delete `STRIPE_TEST_SECRET` and its comment |
| `app/Http/Middleware/HandleInertiaRequests.php` | delete `'is_beta'` from the shared `tenant` prop |
| `resources/js/types/index.d.ts` | delete `is_beta` from `Tenant` |
| `resources/js/Layouts/AppLayout.vue` | delete the `BetaSandboxBanner` import and its one tag |
| `resources/js/Components/Settings/SettingsNav.vue` | drop `'beta-sandbox'` from the `current` union, the `usePage`/`computed` imports, the `beta` computed, and the spread that adds the tab |
| `routes/app.php` | delete the `require` lines for `beta-sandbox.php` and `sandbox.php` |
| `routes/admin.php` | delete the `super-admin.beta` route |
| `app/Http/Controllers/SuperAdmin/SuperAdminController.php` | delete `setBeta()` and the `'is_beta'` line in `index()` |
| `resources/js/Pages/SuperAdmin/Index.vue` | delete `is_beta` from the `Tenant` type, the `Checkbox` import, the `beta` ref and its two assignments, and the "Beta sandbox" `<section>` |
| `tests/Pest.php` | delete `aBetaSalon()` and `aSandboxBooking()` |

### The columns

`tenants.is_beta` — boolean, default false. `tenants.sandbox_state` — nullable
JSON for last action, flaky-network toggle, and the last sample size. Everything
else the sandbox does operates on the existing tables through `tenant_id`.

### The one environment variable

`STRIPE_TEST_SECRET`. Required in production before any tenant is flagged
`is_beta`; may be blank wherever `STRIPE_SECRET` is already an `sk_test_` key.

---

## 2. How the three actions work

### Load sample data — replace on run

Clears the shop's transactional data (the same list "Reset my shop" uses) and
lays down 24 invented customers, their pets, roughly two months of history and
three weeks of what is still to come, on the salon's **own** staff, services and
opening hours. Deterministic per tenant, so a reload gives the same shop.

Replace, not additive, because additive degrades: a third press leaves seventy
customers in a diary meant to be readable and no way back short of a reset.
Because it deletes, it carries a confirmation dialog.

It refuses — in a sentence naming what to do — when the shop has no active
service or nobody bookable, rather than producing an empty diary or inventing
services the owner would then have to delete.

### Fast-forward — shift *and* run

Both halves, because neither alone is honest:

- Shifting timestamps alone is cosmetic where it matters most. A reminder is a
  *delayed queue job*, put on the queue when the booking was confirmed. Moving
  `starts_at` does not move that job, so the appointment slides to tomorrow and
  the reminder still fires next week.
- Running the jobs early alone changes nothing. `bookings:release-expired` asks
  whether a hold is older than fifteen minutes; the hold created two seconds ago
  is not, however early you run it. No-show eligibility opens when an
  appointment is in the past, not because a command ran.

So: every one of the salon's datetimes slides backwards by the interval, in one
transaction, `where tenant_id = ?` on every statement — then the automations
that would have run are run for that tenant, immediately, through the product's
**real** services (`BookingService::cancel`, `BookingService::decline`,
`WaitlistOfferer::expireAndContinue`, `Notifier::reminder`). Nothing is
reimplemented; what this feature contributes is *which rows* and *this tenant*.

**Not moved:** the `tenants` row. Burning trial days per press would eventually
expire the trial, put the shop into read-only via `EnsureSubscriptionWrite`, and
stop the sandbox's own buttons working — a tester locked out of the tool they
are testing.

**Not rewritten:** delayed jobs already on the queue. See "still weak" below.

### Reset my shop — transactional, and not account deletion

Deletes, for that tenant only and in one transaction:
`slot_offers`, `rebook_sends`, `messages`, `loyalty_enrolments`, `bookings`,
`waitlist_entries`, `subjects`, `customers`.

Preserves: the tenant row, the owner's login, staff, services, `service_user`,
availability rules, time off, loyalty **packages**, branding and settings, the
Stripe connection, and the whole subscription/billing record.

---

## 3. Safety

- **`is_beta` is checked on the server on every call**, in the controller *and*
  again inside each of the three services, so a caller that reaches past HTTP
  still cannot wipe a paying salon. The answer is **404**, not 403: a salon
  outside the beta should not learn from an error page that a beta exists.
- **The tenant is never taken from the request.** It comes from
  `ResolveTenant`, which reads the session. A body that names a `tenant_id`,
  `tenant` or `tenant_slug` other than the caller's own is **403** — an explicit
  refusal rather than a silent run against the right shop, which would look like
  success to whoever sent it.
- **Impersonation inherits both.** A super admin wearing an owner's session is
  that owner for these gates, so impersonating an ordinary salon reaches
  nothing, and tampering while impersonating a beta salon is still refused.
- **Nothing is sent.** Sandbox actions run inside `SandboxMute`, which stops
  `Notifier` handing anything to `SendSms` or the mailer while still recording
  the `messages` row so the send log stays honest. Invented customers get
  numbers from Ofcom's reserved 07700 900xxx drama range and `.test` addresses,
  which resolve nowhere. Beta tenants never touch the live SMS ceiling for
  sandbox traffic, because no SMS is ever queued.
- **Stripe cannot reach live mode for a beta tenant** — `StripeTestMode`
  returns a test key or refuses outright; it never falls back to the live one.

---

## 4. Deviations from the brief, and why

1. **Loyalty packages are preserved, enrolments are deleted.** The brief lists
   "loyalty enrolments/packages" among the things reset deletes, and also
   promises settings survive. A package is configured on Settings → Loyalty
   beside the on/off switch — it is settings. An enrolment is a customer's
   progress — it is transactional. Deleting a package would leave the Loyalty
   settings screen blank after a reset, which contradicts the dialog's own
   promise. Enrolments go; packages stay.

2. **The banner sits at the top of the content column, not above the nav.** The
   rail is `fixed inset-y-0 left-0` and full viewport height. Putting a bar
   above it means giving every screen in the product — for every tenant, beta or
   not — a top offset the rail is inset by: a shell rewrite carrying regression
   risk for 100% of salons to move a 33px bar for a handful. It sits where the
   product's four existing global notices already sit, first in the stack, on
   every operator screen.

3. **The three actions are synchronous, not queued.** Two things force it: the
   mute cannot survive a hop onto a queue worker (a queued mailable is sent by a
   worker with normal config), and a job-status field to poll would need either
   a new column — which the brief rules out — or state smuggled into
   `tenants.settings`. So the work is sized to finish inside a request (24
   customers, ~150 bookings; ~0.2s in the suite), the buttons show Inertia's
   real `processing` state, and each returns to a page stating in plain words
   what happened. If the sample shop is ever grown to seventy customers this
   decision needs revisiting.

4. **There is a second integration point outside the namespace.** The brief said
   the Stripe gateway would be the only one. Suppressing notifications for
   sandbox operations *only* — the brief's own wording, "not a real customer
   action" — cannot be done from outside `Notifier`, because that is where the
   decision to queue an SMS or a mailable is made. The alternative considered
   and rejected was relying on invented customers having no contact details,
   which is an accident rather than a mechanism and cannot be asserted. The hook
   is one private method and five one-line guards in one file, all greppable for
   `BetaSandbox` / `SandboxMute`.

5. **`WaitlistOfferer::expireAndContinue()` gained an optional tenant filter.**
   Counted as an integration point above, but it is beta-agnostic: the scheduled
   command still sweeps the platform, and it is a capability the service
   arguably should have had. Safe to leave behind.

6. **`is_beta` is a column, not a `feature_flags` key.** `feature_flags` is a
   JSON blob a super admin edits freely; this flag decides whether a tenant can
   reach live Stripe keys, and a guard that important should be visible in a
   `describe` and indexable.

---

## 5. Still weak

- **A duplicate reminder is possible.** Fast-forward does not rewrite queue jobs
  already delayed, so a booking that was reminded early can be reminded again by
  the original job once the real clock catches up. Inside the sandbox that is a
  second muted row in the send log for an invented customer. Fixing it properly
  means cancelling delayed jobs by identity, which the queue does not support
  without a job-id column.
- **The scheduled commands still sweep the platform on their own clock.** A
  fast-forwarded salon's expired offers would also be picked up by the next
  `waitlist:expire-offers` tick — harmlessly, since the sandbox has already
  processed them, but it means the sandbox is not the only actor.
- **A beta salon whose trial lapses loses the sandbox.** The four routes sit
  behind `subscribed`, like every other operator screen, so a read-only shop
  cannot press the buttons. Deliberate — exempting them would be a fourth
  integration point in middleware — and the fix is to comp beta tenants from the
  console. Worth knowing before a tester reports it as a bug.
- **The Stripe guard is proven without touching Stripe.** `StripeTestMode` is
  asserted directly and through `StripeConnectGateway`'s constructed client
  (`StripeClient::getApiKey()`, reached by reflection). Nothing in the suite
  makes a real API call, so "Stripe accepts this key" is not tested here — only
  that we never hand it a live one for a beta tenant.
- **`SampleData` and `DemoDataSeeder` invent shops separately.** They are
  different tools for different people (a beta owner in production, a developer
  locally) with different safety rules — the seeder refuses outside `local` and
  `testing`, and deletes staff — so they were not merged. They will drift.
- **The banner sits above the page's sticky headers rather than pinning
  them below it.** `ui/Table`'s `sticky top-0` sticks to the viewport top, so on
  a beta salon a scrolled table header passes under the banner. This is already
  true of the four existing notice bars and is not a regression, but it is not
  right either.
- **No end-to-end screenshot coverage.** The sandbox screen and the banner are
  covered by feature tests and the four gates, not by Playwright; the e2e
  baselines are per-screen and none of them is a beta tenant.
