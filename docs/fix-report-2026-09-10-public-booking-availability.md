# Fix report — 10 September 2026

## The public booking page said "fully booked" to a business that had never opened

A tenant with no services, no staff hours and nothing configured was shown to
customers as **"{name} is fully booked — There is nothing free in the diary at
the moment. Leave your number and we will text you the moment something opens
up."** That is a sentence about a busy business, offered with a waitlist form,
to a business with no diary at all.

Fixed. The page now distinguishes three states instead of two. The
genuinely-fully-booked path is byte-for-byte unchanged.

---

## State of the suites

| | Result |
|---|---|
| Pest | **1143 passed**, 13 skipped, 0 failed (was 1134 — this adds 9) |
| Vitest | **210 passed**, 0 failed (was 207 — this adds 3) |
| Playwright — `setup` + `operator` + `console` + `public`, one full pass | **87 passed**, 13 failed |
| Playwright — the 13 failures | `auth` 7, `register` 5, `marketing` 1 — the same 13 named in `docs/audit-report-2026-09-10.md`, stale baselines from the auth/onboarding redesign. None of them is on this surface and none is new |
| Playwright — the 3 specs added here | **3 passed** |
| `check:design` | clean |
| `check:contrast` | clean |
| `check:name` | clean |
| `check:php` (Pint) | clean |
| `check:components` | **fails on the same 3 pre-existing files** — `Settings/Billing/Index.vue`, `Billing/AccessRequired.vue`, `views/pdf/invoice.blade.php`. Untouched here, and in the audit's decision table |

The e2e run was done the way the seed requires: `./scripts/e2e-setup.sh`, then
`npm run build`, then exactly one foreground `./scripts/e2e-playwright.sh` pass.

---

## 1. Reproduction

### What a genuinely new signup does

Reproduced through the real form, not a factory
(`PublicBookingSetupStateTest`, "carries the business name typed at signup"):

```
POST /register  business_name="Bramble & Co"  business_type=groomer
→ tenants row: name "Bramble & Co", slug "bramble-co",
               onboarding_completed_at NULL, booking_page_live false
→ GET /book/bramble-co  →  404
```

`ResolvePublicTenant` requires `onboarding_completed_at IS NOT NULL` **and**
`booking_page_live = true`, and `RegisteredUserController::store()` writes
`booking_page_live => false`. So a brand-new signup's public URL is dark. It
cannot show the bug at that URL.

### Where a real, un-set-up business does see it

Three ways, all reproduced:

1. **`/preview/{token}`** — the "preview your page before you go live" link
   handed to every new tenant. `PreviewBookingController` looks the tenant up
   by `preview_token` only: no onboarding gate, no live gate. It delegates
   straight to `PublicBookingController::show()`. An owner three steps into
   onboarding, previewing their own page, was told their business was fully
   booked. Reproduced end to end in
   `PublicBookingSetupStateTest`, "shows the owner previewing an unfinished
   page the setup state".
2. **A live tenant that has no services.** `booking_page_live` and
   `onboarding_completed_at` can both be set without a service existing —
   `OnboardingController::complete()` checks neither, and super admin's
   `go-live` action sets `booking_page_live` directly. The e2e fixtures
   `bramble-co` and `clover-grooming` are exactly this and were serving the bug
   at `/book/bramble-co` before this change.
3. **A configured tenant that later deactivates every service, or whose only
   staff member's hours are cleared.** Same end state, same message.

### The code path, and where the two states collapsed into one

`PublicBookingController::show()` → `AppointmentSuggester::suggest()` →
`ProposalPayload::suggestion()` → props → `BookingIsland.vue`.

`suggest()` returned `new Suggestion(null, …)` — a null `primary` — from
**four** different places:

| line | reason |
|---|---|
| `$service === null` | the tenant has no active service at all |
| `$slots->isEmpty()` | no staff can perform it, nobody has hours, **or** every slot is taken |
| `$primary === null` | no candidate matched a `ReasonKey` |
| — | (the happy path) |

`BookingIsland.vue` then branched on one thing: `v-else-if="!proposal"`, at
what was line 578. Every one of those four arrived there, and that branch is
the fully-booked-plus-waitlist screen. **That `v-else-if` is where the collapse
happened**, and `AppointmentSuggester::suggest()` is where the information that
would have distinguished them was thrown away.

`AvailabilityEngine::slotsFor()` is shared with the manage-booking reschedule
picker, as expected — but it is not the culprit. It returns an empty
`SlotCollection` and is right to; the caller never asked *why* it was empty.

---

## 2. What was fixed, file by file

### `app/Services/Booking/BookingReadiness.php` — new

The explicit check, run **before** the availability query rather than inferred
from an empty result:

- `hasBookableService()` — at least one `services` row with `is_active = true`.
- `hasStaffWithHours()` — at least one `users` row with `is_active` and
  `is_bookable`, which has at least one `availability_rules` row.
- `isReady()` — both.

`withoutGlobalScopes()` plus an explicit `tenant_id` throughout, matching the
availability engine's isolation rule in DECISIONS.md: this is called from an
unauthenticated host that has no `TenantContext`.

### `app/Services/Booking/AppointmentSuggester.php`

Four lines at the top of `suggest()`. If the tenant is not ready, it returns
`new Suggestion(null, [], false, $customer, null, null, null, setupIncomplete: true)`
and stops — **before** the booking-history query, before `slotsFor()`, before
the `time_off` and `bookings` loads, before the grid.

Before: a tenant with nothing configured ran the full 42-day slot computation
to discover there were no slots. After: two `EXISTS` queries and a return. A
test asserts the absence directly, by listening for any query naming `time_off`
— the one table only the engine reads on this page.

### `app/Services/Booking/Suggestion.php`

- New constructor parameter `bool $setupIncomplete = false`, last and defaulted,
  so `SlotOfferController`'s use of this class is unaffected.
- New `state()`: returns `setup_incomplete`, `fully_booked` or `proposal`. This
  is the single place the three are named.

### `app/Support/ProposalPayload.php`

Two keys added to the payload the page is mounted with:

- `state` — from `Suggestion::state()`.
- `setup_note` — the one vertical-dependent sentence, or `null`. Built here
  because that is where every customer-facing string on this page is built, and
  it reads the noun from `Vertical::definitionFor()`:
  *"This **salon** has not finished setting up online booking, so there are no
  times to show yet."* For a physiotherapy vertical it says "practice", for a
  garage "garage".

  **Note on the brief:** it asked for the wording conventions in
  `config/verticals.php`. That file no longer exists — verticals moved to the
  `verticals` table in phase 13, and `business_noun` is the column. The
  intent is honoured; the source is the table.

### `resources/js/Pages/Public/BookingIsland.vue`

- `tenant.phone` and `suggestion.state` / `suggestion.setup_note` added to the
  prop types. Both optional, so a payload without them behaves exactly as
  before.
- `setupIncomplete` and `messageHref` computeds.
- A new `<section v-else-if="setupIncomplete">` placed immediately **before**
  the existing `v-else-if="!proposal"`:

  > **{name} is not taking online bookings yet**
  > This salon has not finished setting up online booking, so there are no times to show yet.
  > [Message {name}](sms:…) to book.   *(or "Get in touch with {name} to book." when there is no number on file)*

  No waitlist form, no "leave your number", no "we will text you". The `sms:`
  link is the same pattern `ManageIsland.vue` already uses, and it is absent
  rather than dead when the tenant has no phone number.

- **The `!proposal` section is unchanged.** Same heading, same copy, same
  waitlist form, same `joinWaitlist()`. Verified by a Pest test that fills a
  properly configured salon's diary and asserts `state === 'fully_booked'` with
  `setup_note` null, and by an e2e spec that loads the demo tenant and asserts
  the proposal renders as before.

### `playwright.config.ts`

`booking-states` added to the `public` project's `testMatch`. Signed out, which
is who reads this page.

---

## 3. Business name — this is branch (c)

**The screenshot is a factory-generated tenant, not a real signup. No code
change is needed and none was made.**

The evidence, in order:

1. **"Predovic, Herzog and Ward" is Faker output, structurally.** Faker's
   en_US company provider has the format
   `'{{lastName}}, {{lastName}} and {{lastName}}'`
   (`vendor/fakerphp/faker/src/Faker/Provider/en_US/Company.php:10`), and both
   `Predovic` and `Herzog` are in its `lastName` list
   (`…/en_US/Person.php:100` and `:93`). Three surnames in that exact shape is
   not a name a person types into a form.
2. **`TenantFactory` is the only thing in the codebase that generates one.**
   `database/factories/TenantFactory.php:19` — `fake()->unique()->company()`.
3. **The factory also produces exactly the buggy state.** Its defaults are
   `onboarding_completed_at => now()` and `booking_page_live => true`, with no
   services, no staff and no availability rules. That combination is
   unreachable through the signup flow and is precisely what makes the page
   render, and render "fully booked".
4. **A real signup cannot have a null or fake name.** `business_name` is
   `['required', 'string', 'min:2', 'max:255']` in `RegisterRequest`, with the
   message "Enter the name clients will see." `RegisteredUserController::store()`
   writes it straight to `tenants.name`. Confirmed by an added test.
5. **The public page reads the real column.** `PublicBookingController::show()`
   passes `$tenant->name`; `public-shell.blade.php` uses it for the header, the
   `<title>`, the meta description, the JSON-LD `name` and the avatar initial.
   There is no fallback, no default, and no seed data anywhere on that path.

So it is not (a) and not (b). It is (c): a screenshot of a factory or seeded
tenant — most likely a test database or a local seed — and the "fully booked"
half of the bug report is entirely real, while the business name half is an
artefact of where the screenshot was taken.

The question §3 raises about whether a business name should be required before
the page goes live does not arise: it already is required, at the earliest
possible moment, and the page is dark until onboarding completes.

---

## 4. Edge states verified

| Case | Result | Where |
|---|---|---|
| Services exist, nobody has hours | `setup_incomplete` — same state as zero services, not a silent failure | Pest, "treats a tenant with services but nobody with hours" |
| Only inactive / draft services | `setup_incomplete` — `is_active = false` does not count as having a service | Pest, "treats a tenant whose only services are inactive" |
| Hours on one weekday only, today is not that day | `proposal`, correctly — the suggester looks `min(42, horizon)` days ahead, so a Tuesday-only salon on a Sunday proposes the Tuesday. **Not** misclassified | Pest, "proposes an appointment for a salon that opens on one weekday only" |
| Configured salon, every slot in the window taken | `fully_booked`, waitlist offered, copy unchanged | Pest, "keeps the fully-booked waitlist state" |
| Zero availability today but slots further out | Covered by the two rows above and by the pre-existing "returns empty arrays for closed days across the public 14-day window". The date-range window logic was not the cause and was not touched | — |
| Sandbox banner and email-confirmation banner | **Neither exists on this surface.** `BetaSandbox/Banner.vue` is imported in `Layouts/AppLayout.vue` only; the email-verification notice lives in `AppLayout` / `GuestLayout`. `public-shell.blade.php` renders no banner of any kind. Nothing here can interact with them, and `AppLayout` is untouched | Verified by grep; the `operator` Playwright project passed in full |

---

## 5. Anything flagged rather than fixed

### An active service with nobody attached to it — **now fixed, see below**

This was flagged here as a decision item. The decision was taken and it is
fixed; §7 records what changed. The paragraphs below describe the state as it
was found.

The readiness check was asked of the **tenant**: does it have an active service,
and does it have somebody with hours. It was not asked of the specific service
the page was about to propose. So this state slipped through:

> tenant has an active service · tenant has a staff member with hours ·
> but `service_user` is empty for that service

`AvailabilityEngine::staffWhoCanPerform()` returns nothing, slots are empty, and
the page said fully booked.

### Everything from the 10 September audit's decision table

Untouched, as agreed. The three `check:components` failures above are from that
table.

---

## 6. Test coverage added

### Pest — `tests/Feature/Booking/PublicBookingSetupStateTest.php` (new, 9 tests)

1. A tenant with no services and no hours returns `setup_incomplete`, with a
   `setup_note` that names the vertical's `business_noun`.
2. Services but nobody with hours → `setup_incomplete`.
3. Only inactive services → `setup_incomplete`.
4. A configured salon with every Tuesday slot booked out for six weeks →
   `fully_booked`, `primary` null, `setup_note` null. This is the
   don't-break-the-existing-path guard.
5. A one-weekday salon on a day it is closed → `proposal`, on the right date.
6. No `time_off` query is issued for a setup-incomplete tenant — the
   availability computation genuinely does not run.
7. The same query *is* issued for a configured salon, so test 6 cannot pass
   vacuously.
8. A tenant created through the real `/register` flow carries the typed
   business name, has `onboarding_completed_at` null and `booking_page_live`
   false, and its public URL 404s.
9. The same tenant's `/preview/{token}` page renders the real name and
   `setup_incomplete`.

No business-name fallback test, because §3 turned out to be branch (c) and
nothing was changed. Tests 8 and 9 pin the evidence for that finding instead.

### Vitest — `tests/js/islands.test.ts` (3 added, 210 total)

- The setup-incomplete state renders its own heading and note, contains neither
  "fully booked" nor "nothing free in the diary", offers no waitlist button,
  and renders the `sms:` link when there is a number.
- With no number on file, it falls back to "Get in touch with {name} to book."
  and renders no dead link.
- `state: 'proposal'` still renders the 34px appointment heading and none of
  the setup copy.

The pre-existing "offers the waitlist, not an empty picker" test is unchanged
and still passes — its fixture carries no `state` key at all, which is the
check that the new props are genuinely optional.

### Playwright — `tests/e2e/booking-states.spec.ts` (new, 3 specs)

Against a real browser and the real server, signed out:

- `/book/bramble-co` (a seeded live tenant with no services) → `setup_incomplete`,
  the new heading, no "fully booked", no waitlist button, no mobile field.
- `/book/paw` (the fully configured demo salon) → `proposal`, the 34px heading
  with a time in it, a Reserve button, and none of the setup copy.
- The setup state names the business and offers a way to reach it.

Text assertions rather than screenshots, deliberately: the claim is *which
words* each state uses, and this suite's snapshot baselines are only valid
against a pristine seed. A pixel gate on a sentence would be the wrong
instrument and would add three baselines to a set the audit already found
partly stale.

The third state, `fully_booked`, is covered in Pest and Vitest but not in
Playwright, because producing it in the browser means booking out the demo
salon's whole horizon — which is the one thing `slot-race.spec.ts` already does
and which the seed ordering note in `scripts/e2e-setup.sh` warns about.

---

## Comments

`BookingReadiness`, `Suggestion::state()` and the new Pest file are written
without comments, at the user's standing request. That diverges from the
surrounding files, which are heavily commented by design, so the reasoning that
would have been in them is in **DECISIONS.md, "Phase 16 — the public booking
page's third state"** instead. No existing comment was removed.

## Not mine

`.design/mockups/Backend/visual-check/13-mobile-waitlist-app.png` shows as
modified in the working tree (2 bytes). Nothing in this work writes to
`.design/`, and its mtime predates the first command of this session. Left
untouched.

---

## 7. The unassigned-service state — the decision, taken

§5 asked: should the setup-incomplete state also cover a service nobody can
perform? **Yes, at the per-service level.** A customer cannot tell "nobody here
does this" from "this business is busy", and the fully-booked screen offers a
waitlist for a diary that will never produce this service at all.

### What changed

| file | change |
|---|---|
| `app/Enums/SetupReason.php` — new | `no_service`, `no_staff`, `no_staff_for_service`. The one place the three are named |
| `BookingReadiness` | `hasStaffForService(Tenant, Service)`, mirroring `AvailabilityEngine::staffWhoCanPerform()`'s predicate exactly — active, bookable, joined to that service. Plus `reasonFor()`, which is what `isReady()` is now built on |
| `Suggestion` | `bool $setupIncomplete` became `?SetupReason $setupReason`. One field, not a flag plus a reason that have to agree. `isSetupIncomplete()` is derived; `state()` is unchanged |
| `AppointmentSuggester::suggest()` | the per-service check, after the service is resolved and **before** `slots()` |
| `ProposalPayload` | `setup_reason` and `setup_heading` added to the payload; `setupNote()` gained the per-service variant |
| `BookingIsland.vue` | renders `setup_heading`, falling back to the business-name heading when the prop is absent |

### The copy, and why it differs

`no_service` / `no_staff` keep their sentence **byte for byte** — that is the
guard that keeps the nine tests above honest:

> **{name} is not taking online bookings yet**
> This salon has not finished setting up online booking, so there are no times to show yet.

`no_staff_for_service` says something else entirely, and names the service:

> **Full groom is not bookable online yet**
> Nobody at this salon is set up to take full groom online yet, so there are no times to show.

Both take the noun from `Vertical::definitionFor()`, so a physiotherapy vertical
reads "practice" and a garage reads "garage". Neither offers the waitlist.

### Per-service, not per-tenant

A tenant with two services, one staffed and one not, returns two different
states from the same URL depending on `?service=`. Asserted directly:

```
?service={staffed}   → state proposal,          setup_reason null,   primary present
?service={unstaffed} → state setup_incomplete,  setup_reason no_staff_for_service
```

### Still no availability computation

The check is an `EXISTS` before `slots()`, so an unstaffed service does not run
the 42-day grid to discover it has no slots. The same `time_off`-listener
assertion the tenant-level path uses covers this one.

### Tests added — 6 Pest, 2 Vitest

Pest, in `tests/Feature/Booking/PublicBookingSetupStateTest.php` (now 15 tests,
the original 9 unchanged and still passing):

1. A service with no `service_user` rows → `setup_incomplete`,
   `no_staff_for_service`, the service-specific heading and note, **not** the
   business-level sentence, and no `time_off` query.
2. The per-service and per-tenant states share a `state` but differ in
   `setup_reason`, `setup_note` and `setup_heading` — the "different copy"
   requirement asserted as a difference rather than as two literals.
3. A properly staffed service → `proposal`, every setup key null.
4. Two services on one tenant, one staffed and one not, each asserted
   independently.
5. `no_service` is named as the tenant-level reason when there is no service.
6. `no_staff` is named when nobody has hours.

Vitest, in `tests/js/islands.test.ts` (now 212 total):

- The per-service state renders the service heading and note, and none of the
  business-level or fully-booked copy, and offers no waitlist.
- With no `setup_heading` in the payload the island still renders the
  business-name heading — the check that the new key is genuinely optional.

### Suites

| | Result |
|---|---|
| Pest | **1162 passed**, 0 failed |
| Vitest | **212 passed**, 0 failed |
| `check:design` / `check:contrast` / `check:name` / `check:php` | clean |
| `vue-tsc` | clean |
| `check:components` | the same 3 pre-existing files, untouched here |
| Playwright | **93 passed**, 13 failed — the same 13 stale `auth`/`register`/`marketing` baselines as above. Run once at the end of the session, after all three parts |
