# Onboarding steps 2–5 — restyle to registration spec 1a

**Date:** 2026-09-14
**Branch:** `fix/onboarding-owner-service-links`
**Spec:** `DiaryDesk Registration Screens.dc.html`, variant **1a** only (1b excluded)

---

## 1. Files changed

| File | Reason |
| --- | --- |
| `resources/js/Pages/Onboarding/Index.vue` | The whole restyle. Header row gains the logomark and a `Step X of 5` counter; the progress bar now renders five segments instead of four; headlines take `display-light`; the field measure moves from `max-w-measure` to `max-w-auth-col`. |
| `tailwind.config.js` | Exposes the **existing** `--auth-col` token as a `maxWidth` utility. It was previously registered only under `flexBasis`, so `max-w-auth-col` did not resolve. No new token was created. |

Nothing else was modified. No PHP, no routes, no migrations, no step logic.

### Deliberately not changed

`index`, `total`, `isFirst` and `isLast` still derive from `onboardingSteps` (4 entries) and still drive `goBack()` and `onNext()`. Only the two **display** values — the progress bar and the step counter — were switched to the 5-entry `steps` prop. The wizard's step index, routing and submit handlers are untouched, as instructed.

---

## 2. Actual file paths found

The brief assumed `resources/js/Pages/Onboarding/...` with per-step files. That is not the structure.

- **Onboarding is a single 765-line component:** `resources/js/Pages/Onboarding/Index.vue`, holding all four steps as `<section v-if="step === 'basics' | 'business' | 'services' | 'link'">`.
- **Step 1 is a separate page:** `resources/js/Pages/Auth/Register.vue`, which renders through `resources/js/Layouts/GuestLayout.vue`.
- Step definitions: `app/Support/SetupSteps.php` — `ONBOARDING` is `['basics','business','services','link']` (4), while `all()` returns 5 rows including `account`.
- Controller: `app/Http/Controllers/OnboardingController.php`.

---

## 3. Bug A and Bug B — neither reproduces

Both were investigated before any code was written. **Neither defect exists in this codebase**, so no fix was applied and no duplicate test was added (your instruction: skip and report evidence).

### Bug A — `tenants.country` "never persisted"

It persists. The path is complete at every step:

- `country` **is** in `Tenant::$fillable` (`app/Models/Tenant.php:39`).
- `country` **is** validated as `required` in `UpdateBusinessDetailsRequest::rules()` (line 22), with a `prepareForValidation()` that upper-cases it and falls back to the currency's default country when blank.
- `OnboardingController::updateBusiness()` mass-assigns the whole validated payload: `$tenant->update($request->validated())`.

There is already a test asserting exactly the claimed-broken behaviour:
`tests/Feature/Onboarding/OnboardingTest.php:378` — `it('persists country and timezone on the tenant')` submits `country => 'IE'` and asserts `country` comes back `'IE'`. It passes.

### Bug B — `tenants.timezone` "hardcoded to Europe/London"

It is not hardcoded anywhere that reaches the screen. `Europe/London` occurs in only three benign places:

1. `database/factories/TenantFactory.php:21` — a **test factory** default.
2. `database/migrations/0001_01_01_000000_create_users_table.php:16` — a column default on the **users** table, not tenants.
3. `app/Support/Timezones.php:45` — the `GB` entry in the country→zone map, which `forCountry()` also uses as the fallback for an **unrecognised** country code.

The controller passes the tenant's real `$tenant->timezone` through, and the field is a `Combobox` bound to `form.timezone`, so the user's choice is what saves.

**The auto-suggest you asked me to check does exist and is correct.** `Index.vue:254-263` watches `form.country` and sets `form.timezone` from the `countryTimezones` map. It suggests, it does not lock: the Combobox stays editable and line 285 submits whatever `form.timezone` holds.

Verified live in the browser rather than by reading:

| Country selected | Timezone shown |
| --- | --- |
| Ireland | `Europe/Dublin` |
| Germany | `Europe/Berlin` |
| France | `Europe/Paris` |

The screenshot that prompted the report (`03-business-details-app.png`) shows `Europe/London` because the country on it is **United Kingdom** — the correct suggestion, not a stuck default.

---

## 4. Test results

| Gate | Before | After |
| --- | --- | --- |
| `vendor/bin/pest --parallel` | 1281 passed, 7673 assertions | **1281 passed, 7673 assertions** |
| `npm run test:unit` (vitest) | — | 302 passed, 20 files |
| `vue-tsc` (via `npm run build`) | — | clean, build succeeded |
| `check:contrast` | — | all pass |
| `check:name` | — | clean |
| `check:php` (pint) | — | passed |
| `check:design` | standing baseline | unchanged — no new violations |
| `check:components` | standing baseline | unchanged — no new violations |

The "before" figure was measured by stashing the two changed files and re-running the suite.

Two notes on the gates:

- `npm run check` chains with `&&` and still halts at the **pre-existing** `check:components` failures (`Settings/Billing/Index.vue`, `Billing/AccessRequired.vue`, `views/pdf/invoice.blade.php`) recorded in the 2026-09-10 audit. I ran `test:unit` and `check:php` separately, since that halt would otherwise hide them. `check:design`'s standing failures (`Dev/Components.vue`, `lib/staffColour.ts`, plus `.design/mockups/` drift) are likewise the documented baseline. **None of the flagged files are files I touched**, and no hardcoded colour was introduced.
- One intermediate suite run reported `1268 passed, 13 skipped`. It did not reproduce; two subsequent runs on the same working tree both gave `1281 passed, 0 skipped`. It was environmental, not the diff.

---

## 5. What the restyle actually changed

The brief described steps 2–5 as needing the full 1a chrome built from scratch. Most of it was already there — the segmented bar, the footer commit bar with Back + terracotta primary + step counter, the headline/subtext pairing, and the exact `nextLabels` strings (`Continue to details`, `Continue to services`, `Continue to your link`, `Go to my diary`) all pre-dated this change. The real gaps were:

1. **No logomark** in the header row → added `AppLogo` at `:size="26"`, `variant="mark"` (the square outline mark).
2. **No step counter** in the header row → added, right-aligned, matching 1a.
3. **Four progress segments, not five** — the bar iterated `onboardingSteps`, which excludes the account step, so the completed registration step was invisible. Now iterates `steps` (5) and fills through the current one.
4. **Headline weight** — `text-34 tracking-34` without `display-light`, so it rendered Geist 400 rather than Inter Tight 300. Added `display-light`, matching "Set up your business".
5. **Measure** — field blocks were `max-w-measure` (68ch, ~600px+). Now `max-w-auth-col` (560px).
6. Header row gained the `border-b border-rule` divider 1a shows.

### Two spec details worth knowing

- **ALL CAPS was not used, deliberately.** `resources/css/base.css:271` defines `.eyebrow` with `font-variant-caps: all-small-caps` and the comment *"The system bans ALL CAPS and means it"*. The existing sentence-case string `DiaryDesk setup · {{ label }}` already renders as the small caps 1a shows. Adding `uppercase` would have broken the design system to imitate a screenshot of it.
- **The right-hand preview was kept.** "No right-rail/sidebar" refers to 1b's navigation rail. 1a's own FIRST SERVICE artboard keeps the "what the customer sees" card beside the 520px field column, so the service preview and the booking-link QR were preserved.

---

## 6. Assumptions to confirm

1. **Measure is 560px, not 520px.** Per your decision, I reused the existing `--auth-col` (560px) rather than minting a 520px token. 40px wider than the spec.
2. **Step 1 was left alone — and it now visibly disagrees with 2–5.** See section 7.
3. **The brief's own caption contradicts "don't touch step 1."** 1a is labelled *"Unified wizard — screen 1 adopts the chrome of 2–5."* The spec's intent is one chrome across all five screens.
4. **Screenshot width.** App shot at 1440px (the house `visual-check.mjs` convention); the mockup artboards are 1000px-wide cards, shot per-element. Widths differ by design — compare chrome and proportion, not pixel offsets.
5. **Test tenants left in the dev DB.** Driving the real flow required registering accounts. Slugs are `adams-hyatt-<6 digits>`, `tz-check-<6 digits>`, emails `visual+…@diarydesk.test` / `tz+…@diarydesk.test`. I did not delete any rows — say the word and I will.
6. **`public/hot` was stale** and was suppressing all JS/CSS. I moved it to `public/hot.aside` (already in `.gitignore`) so the built assets serve. `npm run dev` recreates it.
7. **No error state exists for step 5.** The booking-link step has no user-editable fields, so there is no validation to trigger. Steps 2, 3 and 4 each have a captured error shot.

---

## 7. Open decision — step 1

You asked to see both before deciding. The pair is saved:

- `01-your-account-app.png` — the app today: **two-pane**, left form + right "Setting up" numbered rail, no segmented bar, no header row, no footer commit bar.
- `01-your-account-mockup.png` — spec 1a: full-width segmented bar, `DIARYDESK SETUP · YOUR ACCOUNT` header with logomark, `STEP 1 OF 5` top-right, footer commit bar.

They are structurally different layouts. `Register.vue` is closer to the 1b pattern you excluded than to 1a. With steps 2–5 now on 1a, the flow changes shape between screen 1 and screen 2 — the exact inconsistency the spec set out to remove.

Bringing step 1 across means editing `Register.vue` and `GuestLayout.vue` (which also serves login, password reset, and other auth screens — so the blast radius is wider than one file). That is beyond the scope you set, so it is **not done and awaiting your call**.

---

## 8. Screenshots

All in `.design/mockups/Backend/visual-check/` (1440px viewport, 2× device scale, fonts settled, every asset load-checked — no dead images, no failed requests).

**The four restyled steps:**

| Step | App | Mockup |
| --- | --- | --- |
| 2 · Business basics | `02-business-basics-app.png` | `02-business-basics-mockup.png` |
| 3 · Business details | `03-business-details-app.png` | `03-business-details-mockup.png` |
| 4 · First service | `04-first-service-app.png` | `04-first-service-mockup.png` |
| 5 · Booking link | `05-booking-link-app.png` | `05-booking-link-mockup.png` |

**Error states (validation triggered live in the browser):**

| File | Error shown |
| --- | --- |
| `02-business-basics-error-app.png` | `The name field is required.` (business name cleared) |
| `03-business-details-error-app.png` | `The phone field must not be greater than 50 characters.` |
| `04-first-service-error-app.png` | `The name field is required.` (service name cleared) |

**Step 1, for the decision in section 7:** `01-your-account-app.png`, `01-your-account-mockup.png`.

The mockup half was rendered from the real design export, staged locally at
`.design/mockups/Backend/DiaryDesk registration screens/` with the matching `_ds` bundle and `support.js`, so both halves were shot in one browser at one viewport.

Note: the folder already held unrelated pairs on the same numeric prefixes (`01-bookings`, `02-waitlist`, `03-overview`, `04-booking-detail`, `05-staff`). Filenames differ, so nothing was overwritten.
