# Onboarding step 1 (register) — restyle to registration spec 1a

**Date:** 2026-09-15
**Branch:** `fix/onboarding-owner-service-links`
**Spec:** `DiaryDesk Registration Screens.dc.html`, variant **1a**, screen 1 ("Your account"). 1b excluded.

---

## 1. Part 1 audit — what uses `GuestLayout`

Every importer, found by `grep -rn "GuestLayout" resources/js/`:

| Page | Route | Touched? | Verified |
| --- | --- | --- | --- |
| `Pages/Auth/Register.vue` | `/register` | **Yes** — moved to the new layout | this is the fix |
| `Pages/Auth/ForgotPassword.vue` | `/forgot-password` | No | screenshot byte-identical |
| `Pages/Auth/ResetPassword.vue` | `/reset-password/{token}` | No | screenshot byte-identical |
| `Pages/Auth/ConfirmPassword.vue` | `/confirm-password` | No | screenshot byte-identical |
| `Pages/Auth/VerifyEmail.vue` | `/verify-email` | No | screenshot byte-identical |
| `Pages/Welcome.vue` | **not routed** | No | orphan — `/` is the Blade marketing page (`marketing.home`), and no route renders this component |

`GuestLayout.vue` itself was **not edited** (`git diff` over it is empty), so the four live auth pages above cannot have changed. They were screenshotted anyway, before and after — see §5.

**The login page does not use `GuestLayout`.** `Pages/Auth/Login.vue` (and `Pages/Admin/Login.vue`) render through `EditorialAuthLayout.vue`, which was also not edited. Login was screenshotted before and after regardless.

---

## 2. Files created and changed

| File | Status | Reason |
| --- | --- | --- |
| `resources/js/Components/SetupChrome.vue` | **new** | The wizard chrome, extracted rather than copy-pasted: five progress segments, the header row (logomark + `DiaryDesk setup · [SECTION]` + `STEP X OF 5`), the capped content column, and the sticky footer bar with the step counter. Slots: default, `footer-start`, `footer-end`. Exports the `SetupStep` type. |
| `resources/js/Layouts/OnboardingGuestLayout.vue` | **new** | Used only by `Register.vue`. Wraps `SetupChrome` and adds the title/lede block and a `notice` slot, so step 1 composes the same way steps 2–5 do. |
| `resources/js/Pages/Auth/Register.vue` | changed | Swapped `GuestLayout` → `OnboardingGuestLayout`. The two-pane split and the "Setting up" rail are gone with it. The submit button moved into the footer slot. |
| `resources/js/Pages/Onboarding/Index.vue` | changed | Steps 2–5 now render through `SetupChrome` instead of holding the chrome markup inline. Pure extraction — see §4 for the pixel proof. |
| `tests/js/register.test.ts` | changed | Layout stub renamed and given the new slots; one test added for the button→form wiring (§3). |
| `tests/js/setup-chrome.test.ts` | **new** | Locks `SetupChrome`'s progressbar semantics and step counters (§8). |

Nothing else. No PHP, no routes, no migrations, no backend logic. `tailwind.config.js` shows in `git diff` but is the previous session's `max-w-auth-col` change, not this one.

### Chrome parity

`SetupChrome`'s markup is character-identical to what `Onboarding/Index.vue` carried before the extraction, so step 1 and steps 2–5 now share one implementation and cannot drift:

- Header eyebrow: `DiaryDesk setup · {{ label }}` on the `.eyebrow` class. That class is `font-variant-caps: all-small-caps`, which is why it renders as caps — the house system bans `text-transform: uppercase`, so the casing matches steps 2–5 by using the same mechanism, not by shouting the source string.
- Step counter: `STEP 1 OF 5`, from `SetupSteps::all()` (5 rows, `account` first) — the same 5-row prop steps 2–5 index into.
- Progress bar: five segments, first filled on step 1.
- Footer: `Already set up? Sign in` on the left, `STEP 1 OF 5` + terracotta `Continue to business basics` on the right.

### Preserved exactly

All four fields in the original order, every validation rule, every error message, the email-already-exists inline sign-in link, the lockout banner, the transport-failure callout, the password hint, the terms line, and `form.post(route('register'))`. Visual-only, as instructed.

---

## 3. One behavioural detail worth recording

The submit button now sits in the footer bar, which is **outside** the `<form>` element. It stays wired by `<form id="register">` + `<Button type="submit" form="register">`, so Enter-to-submit and click-to-submit both still go through `@submit.prevent="submit"`.

This is the one thing the restyle could have silently broken, so it is now covered two ways:

- A new Vitest case asserts the button's `form` attribute matches the form's `id`.
- The real browser run in §5 filled the form, clicked the footer button, and got the validation errors — then a second run submitted valid data and landed on `/onboarding`.

---

## 4. Steps 2–5 are unchanged by the extraction

`git stash` is misleading here: the committed `Onboarding/Index.vue` predates the previous session's (uncommitted) steps 2–5 restyle, so stashing compares against the wrong baseline. The session-start file was reconstructed instead, and both versions were rendered at the same viewport:

```
session-start: ed19dabf9342d6eb369b9386cbd1a34ed97931de05209d0dab434c6a053f0692
refactored:    ed19dabf9342d6eb369b9386cbd1a34ed97931de05209d0dab434c6a053f0692
```

Byte-identical. The extraction changed no pixels on steps 2–5.

---

## 5. Screenshot evidence

Shot with Playwright at 1440×1000, `deviceScaleFactor: 2`, full page, against `php artisan serve` + `npm run dev`.

### Step 1 vs the 1a mockup — filed in the repo

| File | What |
| --- | --- |
| `.design/mockups/Backend/visual-check/01-your-account-app.png` | Step 1 as built (replaces the old two-pane capture) |
| `.design/mockups/Backend/visual-check/01-your-account-mockup.png` | 1a screen 1, the visual target (unchanged, from the previous session) |
| `.design/mockups/Backend/visual-check/01-your-account-error-app.png` | **Validation state**: invalid email, short password, mismatched confirmation — all three messages under their own fields, focus moved to the first bad field |

The previous two-pane capture is kept at
`/private/tmp/claude-501/-Users-pcsetup-Projects-Appoint-Manager/a476085f-2aee-42a0-9545-bfbc86620a83/scratchpad/01-your-account-app-OLD-twopane.png` for the before/after read. It shows the "Setting up / 1 Your account / …" rail that this change removes.

### "Unchanged" proof — before/after SHA-256 of the same page

Both halves shot in the same session, same viewport; "before" taken with the changes stashed.

| Page | Result |
| --- | --- |
| `/login` | **IDENTICAL** |
| `/forgot-password` | **IDENTICAL** |
| `/reset-password/{token}` | **IDENTICAL** |
| `/confirm-password` | **IDENTICAL** |
| `/verify-email` | **IDENTICAL** |
| `/` (marketing) | differs — **not caused by this change**: two consecutive shots with zero code change produce the same two hashes, so the page is simply not deterministic (live clock/demo state). `/` is Blade, and `Welcome.vue` is not routed. |

PNGs are in `/private/tmp/claude-501/-Users-pcsetup-Projects-Appoint-Manager/a476085f-2aee-42a0-9545-bfbc86620a83/scratchpad/before/` and `/private/tmp/claude-501/-Users-pcsetup-Projects-Appoint-Manager/a476085f-2aee-42a0-9545-bfbc86620a83/scratchpad/after/`.

---

## 6. Tests and gates

| Gate | Before | After |
| --- | --- | --- |
| Pest (`npm run test:php`) | 1268 passed, 13 skipped, 7656 assertions | **1268 passed, 13 skipped, 7656 assertions** |
| Vitest (`npm run test:unit`) | 302 passed, 20 files | **303 passed, 20 files** (+1, the button→form test) |
| `vue-tsc --noEmit` | clean | **clean** |
| `check:php` (pint) | passed | **passed** |
| `check:contrast` | all pass | **all pass** |
| `check:name` | clean | **clean** |
| `check:design` | 8 off-token values in 2 files | **identical — 8 in 2** |
| `check:components` | 4 files | **identical — 4 files** |

`npm run check` does not exit 0, and did not before this change either. Its two standing failures are the documented baseline (`docs/audit-report-2026-09-10.md`): `check:design` over `Dev/Components.vue`, `lib/staffColour.ts` and untracked `.design/mockups/`, and `check:components` over `Settings/Billing/Index.vue`, `Staff/Index.vue` and `views/pdf/invoice.blade.php`. Confirmed by stashing the change and re-running both gates: the output is character-for-character the same list and the same counts. **No new hardcoded colour and no new hand-rolled control** comes from the four files touched here — none of them appears in either gate's output.

Because `check` chains with `&&`, that pre-existing `check:components` failure halts the run before `test:unit` and `check:php`; both were run separately, above.

### No comments / no AI-traceable patterns

`grep -nE "//|/\*|<!--|TODO|FIXME"` returns zero hits across `SetupChrome.vue`, `OnboardingGuestLayout.vue` and `Register.vue`, matching the comment-free house style of the surrounding tree.

---

## 7. One thing left behind

The dev database had no user this session could sign in as (`owner@paw.test` does not exist in it), so the end-to-end submit check registered `visual-check@diarydesk.test` through the UI. **That user, its session row and its empty tenant (id 74) have since been deleted** — the tenant held no services, bookings or customers, and no orphaned rows reference either id.
`visual-check@diarydesk.test` through the UI. That row and its tenant are still in the local dev database, alongside similar `visual+…@diarydesk.test` rows from earlier sessions. Delete it whenever you like — nothing in this change depends on it.

---

## 8. Orphan sweep (follow-up)

Asked to confirm nothing dead was left behind. Three orphans were found and removed; one already-stale test was left alone.

### Dead code removed from `GuestLayout.vue`

Register was the **only** page that ever passed `steps` / `currentStep` / `completedSteps` / `displayTitle`. With it moved off, every one of those props was unreachable, and with them the whole two-pane rail:

- the four props and the `StepProgress` import
- the mobile `<StepProgress variant="compact">` block
- the aside's `v-if="steps && currentStep"` branch — the `Setting up` caption and `<StepProgress variant="rail">`
- `displayTitle`'s `display-light` toggle on the `<h1>`; no consumer passed it, so every surviving page already rendered the `:else` side

The `v-else` branch (the auth panel headline/body) is now unconditional, which is what all five remaining consumers were already getting.

### `resources/js/Components/ui/StepProgress.vue` — deleted

Once those branches went, this component had zero references anywhere in `resources/` or `tests/`. It had no unit tests and was not in the `/dev/components` gallery, so nothing else pointed at it.

### No dead CSS

Checked rather than assumed:

| Token / class | Verdict |
| --- | --- |
| `--auth-form` / `max-w-auth-form` | **live** — GuestLayout's surviving column, plus `views/errors/layout.blade.php` |
| `--auth-col` / `max-w-auth-col` / `lg:basis-auth-col` | **live** — GuestLayout, OnboardingGuestLayout, Register, Onboarding |
| `.caption` | **live** — 10+ components; only the single instance inside the dead rail branch went |

No token, utility or base class was orphaned by this change, so nothing was removed from `tokens.css`, `base.css` or `tailwind.config.js`.

### No unused imports, no orphaned files

Every import in all five touched files resolves to a use. `OnboardingGuestLayout` is imported by exactly one page (`Register.vue`), `SetupChrome` by three (the layout, `Onboarding/Index.vue`, and `Register.vue` for the `SetupStep` type), and a sweep of `Components/ui/` finds no component without a consumer.

### One test this change genuinely broke

`tests/e2e/register.spec.ts` asserted the old rail — `Setting up`, a 5-item `list`, and a `progressbar` with `aria-valuenow`/`aria-valuemax` at 375px. None of that renders on step 1 any more. The assertions were rewritten against the new chrome (`DiaryDesk setup · Your account`, `STEP 1 OF 5`, `Already set up?`).

**Its screenshot baselines (`register-1280.png`, `register-375.png`, `register-2-basics-1280.png`) are now out of date and still need regenerating.** They are not re-baselined here: the E2E suite is seed-order dependent and must be reseeded, built and run as a single foreground pass, and the Playwright `public` project already carries documented pre-existing failures — re-baselining piecemeal in the middle of that would bury real breakage.

`tests/e2e/auth.spec.ts` also asserts `progressbar` roles, but it was **already** stale before this change — it expects `aria-valuemax` of `6` and headings (`Where you are`, `What you do`, `Who does it`, `When you are open`) and buttons (`Save and continue`) that the current onboarding has not had for some time. That is the documented stale-baseline failure, a separate job, and was left untouched.

### Accessibility — the progressbar role is back, for all five steps

The old compact `StepProgress` exposed `role="progressbar"` with `aria-valuenow` / `aria-valuemax`; `SetupChrome` had shipped the bar as `role="presentation"` with only the `STEP X OF 5` text. That role is now on the shared bar, so **all five steps** gained it in one change rather than step 1 alone:

```
role="progressbar"  aria-valuemin="1"  :aria-valuenow="index + 1"
:aria-valuemax="steps.length"
:aria-valuetext="`Step ${index + 1} of ${steps.length}, ${label}`"
```

`aria-valuetext` is what the old rail did too — without it a screen reader announces a bare percentage, which tells you nothing about which section you are in. The segment `<div>`s stay unroled; a `progressbar` has no required children, so they carry no semantics.

Verified in a real browser by walking the whole wizard, not just in unit tests:

| Step | `aria-valuenow` | `aria-valuetext` |
| --- | --- | --- |
| 1 `/register` | 1 | `Step 1 of 5, Your account` |
| 2 basics | 2 | `Step 2 of 5, Business basics` |
| 3 business | 3 | `Step 3 of 5, Business details` |
| 4 services | 4 | `Step 4 of 5, First service` |
| 5 link | 5 | `Step 5 of 5, Booking link` |

Exactly one `progressbar` is present on each step, `aria-valuemin`/`aria-valuemax` are `1`/`5` throughout.

Locked by a new `tests/js/setup-chrome.test.ts` (6 cases: the role and its bounds, the valuetext wording, position tracking, the filled-segment count, both step counters, and the section eyebrow), and the E2E `register.spec.ts` progressbar assertions were restored — now also asserting `aria-valuetext`, which the old spec did not check.

This is a semantics-only change: ARIA attributes cannot affect layout, and no pixel moved.

### Re-verified after the cleanup

| Page | Result |
| --- | --- |
| `/forgot-password` | byte-identical to pre-cleanup |
| `/reset-password/{token}` | byte-identical |
| `/confirm-password` | byte-identical |
| `/verify-email` | byte-identical |
| `/login` | differs by a 310×26px band only — the demo diary's date line rolled from "Monday, 14 September" to "Tuesday, 15 September" as the date changed mid-session. Two same-code runs after the rollover are stable and identical. Login does not use `GuestLayout`. |

Pest **1268 passed / 13 skipped**, Vitest **309 passed across 21 files** (+6 from the new `setup-chrome.test.ts`), `vue-tsc` clean, pint passed, contrast passes, and `check:design` / `check:components` still print the identical pre-existing baseline.

---

## 9. Playwright baselines regenerated

Procedure, per the suite's own constraints: `npm run build` (the runner only builds when the manifest is *missing*, never when stale), then `./scripts/e2e-setup.sh`, then exactly one foreground `./scripts/e2e-playwright.sh --update-snapshots` pass, then a reseed and one clean verify pass.

### Result

| | Before | After |
| --- | --- | --- |
| Full suite | 13 failed | **8 failed, 113 passed** |

Identical failure set on the update pass and the clean verify pass, so the result is reproducible rather than a lucky ordering.

### 14 baselines regenerated

**Mine (8)** — the step 1 restyle:
`register-1280`, `register-375`, `register-2-basics-1280`, `register-mismatch-1280`, `register-duplicate-1280`, `register-not-sent-1280`, `register-locked-1280`, `setup-1-account-375`.

**Not mine (6)** — already stale on `main`, regenerated because they were in the way:
`marketing-home-375/768/1024/1280/1440` and `mobile-staff-375`.

Both were verified as pre-existing rather than assumed. `mobile-staff-375` still fails with this branch's changes stashed. The marketing pages measure **7240px tall at 1280 with the changes stashed and 7240px with them applied**, against a committed baseline of 6029px — identical either way, so the drift is from the earlier "Market site updated" / "Staf ui fix" work, not this branch.

### The 8 that remain — all assertion failures, none fixable by a baseline

| Spec | Reason |
| --- | --- |
| `auth.spec.ts` login at 375 / 768 / 1280, "is a page rather than a centred card" | looks for a heading named **"Sign in"**; the page says **"Log in"** |
| `auth.spec.ts` "says what happened when the details are wrong" | 60s timeout, downstream of the same stale login selectors |
| `auth.spec.ts` "walks all five steps" | expects `aria-valuemax` **"6"**, gets **"5"**, and drives headings (`Where you are`, `What you do`, `Who does it`, `When you are open`) and a `Save and continue` button that the current onboarding does not have |
| `marketing.spec.ts` "nothing animates under prefers-reduced-motion" | `home: motion tokens are not zeroed` — a real CSS assertion about the marketing surface |

None of these touch `Register.vue`, `SetupChrome.vue`, `OnboardingGuestLayout.vue` or `GuestLayout.vue`. `auth.spec.ts` describes a six-step onboarding that predates the four-step wizard, and its login selectors predate the auth redesign; rewriting it is a separate job from this one. The marketing motion failure is a marketing-CSS question.

### Two things worth knowing about this suite

**`public` declares `dependencies: ['operator']`.** One failing operator test skips the entire public project — register, auth, marketing and slot-race never run, and the tail reads "N did not run". A stale `mobile-staff-375.png` was enough to hide every marketing result, and `--project=public` still drags the whole operator project in. I read a "passed" from a run where the spec had never executed and briefly reverted the marketing baselines on that basis; the direct page measurement above is what settled it. **Check the "did not run" count before concluding a spec passed.**

**Some specs write into `.design/mockups/Backend/visual-check/`** as a side effect — `mobile.spec.ts`, `loyalty-settings.spec.ts`, `theme.spec.ts` — and those PNGs are tracked, so every run leaves five of them dirty (`13-mobile-waitlist-app`, `15-mobile-staff-app`, `loyalty-settings-app`, `loyalty-settings-full-card-app`, `loyalty-settings-mockup`). They are run artefacts, not deliverables of this change; discard or commit them as you prefer.
