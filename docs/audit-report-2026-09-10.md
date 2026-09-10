# Full-stack audit — 10 September 2026

Covers the pre-existing application and everything built in this session's
Part 1 (mobile views) and Part 2 (manage-booking edges).

**No high-risk item was auto-fixed.** Everything touching payment or refund
logic, the tenant-scoping mechanism itself, existing data, or a product
decision is in the second table and untouched.

## State of the suites

| | Result |
|---|---|
| Pest | **1134 passed**, 13 skipped, 0 failed |
| Vitest | **207 passed**, 0 failed |
| Playwright — `setup` + `operator` | **39 passed**, 0 failed |
| Playwright — `console-setup` + `console` | **5 passed**, 0 failed — was 1 failed / 4 never run, fixed below |
| Playwright — `public` | **13 failed** (`auth` 7, `register` 5, `marketing` 1) — all pre-existing, see below |
| `check:design` | clean |
| `check:contrast` | clean |
| `check:name` | clean |
| `check:php` (Pint) | clean |
| `check:components` | **fails on 3 pre-existing files** — see below |

`npm run check` is therefore **not** clean, on `check:components` alone, for
three files this session did not write. Both are in the second table rather
than fixed. Everything else the gate covers passes.

### The pre-existing failures, attributed

Measured, not assumed. The working tree held 173 uncommitted files from prior
sessions when this session began; they were committed as-is
(`f135701`) so each part could land as a separately revertible commit.

- **`check:components` was clean at `98ed8ce`** (the last commit before that
  uncommitted work) and fails now. The violations arrived with the
  checkpointed WIP, not with this session.
- **The Playwright `public` project fails 13 specs** (`auth` 7, `register` 5,
  `marketing` 1). Verified by stashing this session's work, rebuilding the seed
  and running the full suite: the same specs fail at the pre-change baseline.
  This session introduces none. They are stale expectations and snapshots left
  by the auth and onboarding redesign in the checkpointed WIP —
  `RegisteredUserController`, `RegisterRequest`, `StepProgress` and
  `EditorialAuthLayout` were all in that modified set, and their baselines were
  never regenerated. They are the same class of breakage as the console failure
  fixed below, and are left alone because repairing a redesigned screen's
  baselines means deciding whether the screen is finished, which is not a call
  to make inside an audit.
- **Total passing across all four Playwright projects went from 58 to 83**, and
  the count that "did not run" from 4 to 0.
- **One red Pest test on `main`** was fixed as part of the checkpoint commit so
  every later part had a green baseline: `resources/views/marketing/home.blade.php`
  printed an invented "£1,340 deposits held" aggregate and two diary chip prices
  (£28, £60) that are not seeded service prices, which `MarketingNavTest` guards.

---

## Fixed automatically

| File | Issue | Fix applied | Test |
|---|---|---|---|
| `app/Providers/AppServiceProvider.php` | `booking-manage` was rate-limited `by(ip\|token)` only. Every guess in a token-space walk carries a different token, so each lands in its own bucket and the limit never trips — it capped hammering of one known link and did nothing about enumeration, which is the property an unauthenticated magic link depends on. | Added a second, per-IP limit from the new `config('booking_management.rate_limit_per_ip_per_minute')`. Both limits apply. | `ManageBookingTest` — "caps how hard one link can be hammered" and "caps a walk through the token space from one address" |
| `app/Http/Controllers/ManageBookingController.php` | A dead token hit `abort_if(404)` and rendered the app's generic error page. A malformed token reached a database query. | `booking()` returns null; every unresolvable token — never valid, wrong shape, another tenant's — returns one byte-identical `booking-link-inactive` page. Format is checked against `config('booking_management.token_length')` before any query. | `ManageBookingTest` — "answers every unresolvable token with one identical response", "rejects a token too short to be one without touching the database" |
| `app/Http/Controllers/ManageBookingController.php` | `cancelConsequence()` branched on `deposit_status !== Paid`, so a salon with deposits switched off — whose bookings are `Paid` for £0 — offered customers "Cancel and refund £0.00", which reads as a refund that is coming. Found by looking at the rendered page, not by a test. | Zero is treated as no deposit. Copy only; no change to what is refunded. | Covered by `SelfServiceTest`'s existing £10 assertions (unchanged) plus the e2e render |
| `app/Http/Controllers/BookingController.php` | The bookings list had **no sort tiebreaker**. Every sort it offers has ties by construction (two appointments at 09:00, four all `confirmed`), so MySQL was free to order tied rows differently between identical queries — a paginated list that can show one row twice and skip another. It also made the 768px snapshot flaky. | `orderBy('bookings.id', 'desc')` as the final key. | Proven by the snapshot suite now passing across two full seed rebuilds; `tests/Feature/Booking/ListFiltersTest.php` and `ListPaginationTest.php` still pass |
| `app/Http/Controllers/TwilioInboundController.php` | The courtesy opt-out reply called `$sms->send()` unguarded. The consent change is already persisted at that point, so a Twilio failure returned a 500 and made Twilio retry a webhook whose real work was done. | `try`/`catch (Throwable)`, `report()` plus a warning log, still returns `ok`. | Existing `SmsConsent` coverage; the reply is empty by default so this path is normally inert |
| `tests/Feature/Booking/SelfServiceTest.php` | "returns 404 for an invalid or tampered public token" asserted against `/b/...`, which is **not a path this suite serves** — with `APP_DOMAIN` unset the book surface sits under `/book`. Both requests 404'd because no route existed there, so the test passed without reaching the controller and would have passed with token checking deleted. | Goes through `route()`. | The test itself, now meaningful |
| `resources/views/marketing/home.blade.php` | An invented "£1,340 deposits held" figure and two illustrative diary prices that are not seeded service prices. | Aggregate dropped; chips use real seeded prices (£25, £35). | `MarketingNavTest` (was red, now green) |
| `.env.example` | `BILLING_LEGAL_NAME`, `BILLING_LEGAL_ADDRESS`, `BILLING_COMPANY_NUMBER`, `BILLING_VAT_NUMBER` are read by `config/billing.php` and appear nowhere in `.env.example`. All four default to null, so a fresh deploy issues invoice PDFs and CSVs with no legal name, address, company number or VAT number — not a valid invoice, and silent. | Documented with the consequence stated. Documentation only; no code reads this file. | n/a |
| `app/Services/Stripe/FakeStripeGateway.php` | No way to exercise a refund Stripe refuses, so the `RefundPending` path had no test. | Added `$throwOnRefund`, matching the existing `$throwOnCreate`. Test-support class, unreachable outside `testing`. | `ManageBookingTest` — "leaves the booking owing a refund rather than marked refunded" |
| `tests/e2e/console.setup.ts`, `tests/e2e/console.spec.ts` | **The entire super-admin surface had stopped being tested and nothing said so.** Both waited on a heading named "Console" on `/admin/login`. That screen was redesigned in the checkpointed WIP: "Console" is the `<title>` now and the `<h1>` is a sentence. The setup therefore failed, and because all four console specs depend on it, every run reported "1 failed, 4 did not run" — which reads like one broken login, not like zero coverage of the console. | Both now wait on the email field the setup is about to fill: the thing they actually need, and one that does not move when copy does. The five snapshots were regenerated against the redesigned screens. | All 5 console specs now run and pass, including the assertions inside them — `data-density="console"`, the tenant list's trouble-first sort, and impersonation naming whose session it borrows |
| `tests/Feature/Performance/ListQueryCountTest.php` | No N+1 regression guard existed on any list page. | New: six pages seeded at 3 rows and at 30, asserting the query count does not move. Measured warm — cold, the *larger* list came out two queries cheaper and the comparison said nothing. | The file itself; all six pass |

### Config values introduced this session

`config/ui.php` and `config/booking_management.php` read no environment
variables, so neither needs a `.env.example` entry. `MobileBreakpointTest`
asserts `config('ui.mobile_breakpoint')` against `tailwind.config.js` so the
mirror cannot drift.

---

## Needs your decision

Nothing in this table has been touched.

| File / area | What was found | Why flagged rather than fixed | Recommendation |
|---|---|---|---|
| `resources/js/Pages/Settings/Billing/Index.vue`, `Settings/Billing/AccessRequired.vue` | 15 hand-rolled `<button>`, 3 hand-rolled `role="dialog"`, 8 controls wearing `Button.vue`'s classes by hand. This is why `npm run check` is not clean. Arrived with the checkpointed WIP; `check:components` was clean at `98ed8ce`. | Payment-adjacent UI, and a 26-control rewrite of the screen that manages subscriptions, cards and invoices. Large, and exactly the class of change the brief puts behind explicit approval. | Migrate to `ui/Button` / `ui/Modal` / `ui/ConfirmDialog` as its own piece of work with the billing suite green either side. It is mechanical but it is not small. |
| `scripts/check-components.mjs` — `NO_VUE_TABLE` | `resources/views/pdf/invoice.blade.php` fails the `table` rule. A PDF mounts no Vue, so `ui/Table` is unreachable from it by construction, and an invoice is genuinely tabular. | Widening a quality gate's scope is an owner's call, and the checker's own comment says the two exempt directories "are named so that adding a third is a visible decision". | Add `resources/views/pdf/` to `NO_VUE_TABLE`. It is one line and it matches the rule's own stated premise, which is why `views/mail/` is already there. |
| `tenants.card_last4` / the refund's masked card | §2.4 of the brief asks the cancel screen to show the refund destination as "•••• 4241". `card_brand`/`card_last4` on `tenants` are the **salon's own subscription card**. Showing them to a customer would leak the owner's card digits to a stranger. The customer's deposit card is not stored anywhere — it exists only on the Stripe PaymentIntent. | Payments. Needs a new column, a webhook to populate it from the charge's payment method, and a PCI-scope decision about storing it at all. | Leave the refund line naming the amount and not the instrument, as built. If the masked card is wanted, take it from the PaymentIntent's `payment_method_details.card.last4` at charge time into a new `bookings` column — never from the tenant. |
| `RateLimiter::for('calendar-feed')` | Identical shape to the `booking-manage` bug that was fixed: `by(ip\|token)`, so it does not limit a walk through the feed-token space either. | The traffic profile is different and that is the whole point. Calendar clients poll one token repeatedly, and several staff behind one office NAT share an IP — a per-IP cap there can silently break real subscriptions. | Consider a per-IP limit set well above normal polling (calendar clients poll every 5–15 minutes), or accept the risk given the token is 40 random characters. Deliberately not changed. |
| Billing models are not tenant-scoped | `BillingReceipt`, `PaymentFailure`, `AuditLog` carry `tenant_id` but do **not** use `BelongsToTenant`. Every read path checked does filter correctly — `BillingPageData::invoices()`, `::pastDueBanner()`, `Settings/BillingController::export()` all `where('tenant_id', …)`, and `::download()` checks ownership and 403s — so there is no leak today. The isolation is manual, with no safety net for the next query somebody writes. | The brief puts the tenant-scoping mechanism itself out of bounds, and adding a global scope to a billing model changes every existing query against it, including the webhook and super-admin paths that legitimately run without tenant context. | Add `BelongsToTenant` to the three, then audit each existing query for a now-redundant `where` and each system-context call for a needed `withoutGlobalScopes()`. Worth doing, not worth doing quietly. |
| `SuperAdminController::cloneSetup()` / `Services/Onboarding/TenantCloner` | **Zero test coverage.** A super-admin action that copies one tenant's setup into another — the single place in the codebase that deliberately writes across a tenant boundary. | Writing tests is safe, but the brief asks for a prioritised list of gaps rather than speculative tests, and a bad test here would give false confidence about tenant isolation. | Highest-priority coverage gap found. Wants a test that a clone copies services, staff and hours into the target tenant and copies **nothing** into any third tenant. |
| `resources/js/Pages/Services/Show.vue` | Orphaned. `services.show` is referenced only by its own route definition and three tenancy tests that use it as a convenient route-binding fixture — **nothing in the UI links to it**. The screen shows a service's duration and price and nothing else, in a card the design system discourages. | It looks dead to a reference search but three real tenancy tests would break with it, and whether a service record page *should* exist is a product question. | Either wire it up from `Services/Index.vue` and finish it, or delete it and point `RouteBindingOrderTest`, `MiddlewareTenancyTest` and `TenantIsolationTest` at another bound route. |
| The mockup's beta strip | "Beta · SMS auto-fill is free until 1 Nov", dismissible, above the day header. Does not exist in the app at any width. A different banner — `BetaSandbox/Banner`, gated on `tenant.is_beta` — already occupies that slot in `AppLayout` and says payments are test-only. | The brief explicitly says to flag this rather than guess. It is a **pricing commitment with a date**, not a layout decision, and two stacked beta strips on one screen is a design problem. | Decide three things: whether it is universal or mobile-only; where "1 Nov" comes from (it wants a config value, not a literal); and how it coexists with the sandbox banner. Then it is an hour's work. |
| The mockup's per-row waitlist Offer button | The mobile mockup draws an "Offer" button on every waitlist row. The app offers a **freed slot** to the queue — "Offer to 3 waiting", on the dashboard and the diary — and has no per-entry offer endpoint. | The brief says the offer-triggering logic is untouched. A button that only looked like one would be worse than none. | Confirm the mockup means the existing freed-slot offer. If a manual per-entry offer is genuinely wanted, it is a new endpoint plus an SMS path, not a layout change. |
| `bookings.public_token` is a UUID | §2.1 suggests `config('booking_management.token_length')` at ≥32 characters. The token is `Str::uuid()` — 36 characters, 122 bits — which meets the entropy bar but is not the 40-character random string Calendar Sync uses, and it is **not independently revocable**: a leaked link can only be killed by cancelling the booking. | Regenerating the column would invalidate every manage link already sent in confirmation texts and emails, and it is existing data. | Leave the column. If revocability is wanted, add the `booking_management_tokens` table the brief describes alongside it and migrate new bookings only. |
| `scripts/e2e-playwright.sh` | Builds assets only when `public/build/manifest.json` is **missing**, never when it is stale. A changed component is silently tested as its previous build — which happened during this session and produced a confusing pass. | It changes how the suite runs for everybody, and a build on every run costs ~5s. | Either always build, or compare the manifest's mtime against `resources/` and rebuild when older. |
| `config('services.sentry.dsn')` | Read in exactly one place: `marketing/privacy.blade.php`, to list Sentry as a data processor. There is **no Sentry package in `composer.json`**. Setting `SENTRY_LARAVEL_DSN` therefore makes the privacy policy declare a processor that receives nothing. | It is a privacy-notice correctness question, not a bug. | Either install the SDK or drop the clause, so the notice matches what actually happens. |

---

## Checklist, worked through

Every category is reported, including the ones that turned up nothing.

**1. Tenant isolation — one finding, no leak.** All 14 tenant-owned models use
`BelongsToTenant`. Of the 9 that do not, `Tenant`, `Vertical`, `StripeEvent`,
`WebhookFailure` and `BillingCounter` are correctly unscoped (no `tenant_id`);
`BillingReceipt`, `PaymentFailure` and `AuditLog` are flagged above. All 44
files using `withoutGlobalScopes()` were read: every one is either a public
token surface with no tenant context (`ManageBookingController`,
`SlotOfferController`, `IcalFeedController`), a super-admin or system context
(`SuperAdminController`, `ImpersonationController`, `TwilioStatusController`,
the console commands), or is paired with an explicit
`where('tenant_id', …)` — `OverdueController::recentSends()`,
`ProfileController::ownerExists()`. The one that is not tenant-filtered,
`ProfileController`'s upcoming-bookings query, is filtered by `staff_id`, which
belongs to exactly one tenant. **No cross-tenant read or write was found.**
`Settings/BillingController::download()` checks receipt ownership and 403s, so
the invoice route is not an IDOR.

**2. Error handling — one finding, fixed.** `TwilioInboundController` above. No
empty `catch {}` block exists anywhere in `resources/js`. Every other
controller touching Stripe or Twilio (`BillingController` ×2,
`PaymentSettingsController`, both webhook controllers) has `try`/`catch` around
its gateway calls. Part 2 added user-visible failure paths with retry to both
manage-booking submits.

**3. Validation — clean.** 26 form request classes. No controller passes
`$request->all()` into `create`, `update` or `fill`. Every email field carries
the `email` rule. The three classes without `authorize()` are two traits and
`ProfileUpdateRequest`, which acts only on the authenticated user's own record.

**4. Dead code — one finding.** Confirmed by reference search, not assumption.
**Zero** unreferenced Vue components across all of `resources/js/Components`.
Of 175 named routes, the only three with no reference are
`sanctum.csrf-cookie`, `storage.local` and `storage.local.upload`, all
framework-provided. No app config key is unread — the 44 that appear unread are
all consumed internally by Laravel. The one real finding is
`Services/Show.vue`, flagged above.

**5. N+1 queries — clean, and now guarded.** Bookings index, Waitlist index,
Customers index, the diary, the booking record and the Overdue list each cost
the *same* number of queries at 30 rows as at 3. `tests/Feature/Performance/ListQueryCountTest.php`
keeps it that way.

**6. Design token compliance — clean.** `check:design` passes over 215 files;
`check:contrast` passes including all six tenant brand presets; `check:name`
clean. `check:components` fails only on the three files in the second table.

**7. Missing fallback states — clean.** Every page under `resources/js/Pages`
outside `Dev/` and `SuperAdmin/` carries an empty, loading or error affordance,
with three exceptions, all correct: `Profile/Edit.vue` delegates to three
partials that own their own states, `Services/Show.vue` renders passed props
with nothing to be empty, and `Settings/Payments.vue` uses `ui/Callout` for its
error state plus `form.processing` for loading. Part 2 added the states the
manage page lacked: a finished appointment, zero availability across the whole
window with the horizon named, and a submit failure that says nothing was
changed and offers a retry.

**8. Config hygiene — one finding, fixed.** All 94 distinct `config()` paths
used across `app/`, `routes/` and `resources/views/` resolve to a defined key.
No typo'd path returns null by accident: the 15 that read null do so because
their environment variable is unset in the test environment, or are documented
nulls like `billing.sms_trial_included`. The `.env.example` gap is fixed above.
`CALENDAR_SYNC_UID_DOMAIN` and the seven `CUSTOMERS_*` tuning keys are also
undocumented there but all have correct defaults in config, so they are noted
rather than fixed.

**9. Test coverage gaps — prioritised, not papered over.** The largest gap
found was not a missing test but **four tests that had silently stopped
running** — see the console fix in the first table. Highest remaining:
`TenantCloner` / `cloneSetup`, flagged above — it writes across a tenant
boundary and has no test at all. After that, `Services/Billing/ReceiptPdf` and
`ReceiptRecorder` have no test naming them, though the six files in
`tests/Feature/Billing/` exercise the webhook and subscription-state paths that
drive them. The 6 policies have no dedicated test file but are exercised
through routes by `TenantIsolationTest`, `MiddlewareTenancyTest` and
`ContactVisibilityTest`; `BookingPolicy::viewContact` is covered directly by
`ContactVisibilityTest`. No speculative tests were written for these.

**10. Security spot-checks — clean.** No secret appears in any tracked file:
every `sk_live_` match is an obvious fake in a test (`sk_live_notarealkey`) or
prose in `DEPLOY.md` warning against it. No `.env` is tracked. The built
JavaScript bundle in `public/build/assets` contains no key, token or Twilio
identifier. `env()` is called outside `config/` in two places — one is a
local-only console command, the other is `FREEZE_NOW`, which refuses production
outright, requires an explicit value and swallows a parse error. Both public
token endpoints were re-read for leakage: `booking-manage` now returns one
identical response for every dead token, and `IcalFeedController` returns a
plain-text 404 that names nothing.
