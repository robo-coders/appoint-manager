# Fix report — 10 September 2026 — the alert system

## The brief's premise, corrected

> "The app currently has no consistent alert/confirmation system."

It had one, and it was already the majority of the surface. `resources/js/lib/toast.ts`
plus `resources/js/Components/ui/Toaster.vue` existed, were mounted once in
`AppLayout`, were called from seven places in the client, and carried **60**
server-side `->with('toast', …)` flash sites behind them. There was also a
specimen for it on `/dev/components` and a Vitest spec asserting its tone.

So this is not a greenfield build. It is a **consolidation and a redesign**: the
existing system was three tones on a bordered white card with per-call timeouts,
and the confirmed direction is two tones on a solid fill with a config-driven
duration. Everything below is written against what was actually there.

What did *not* exist, and does now: the two-tone solid-fill treatment, a
`toast.success` / `toast.error` API, a config-driven duration, an assertive
live region for failures, an empty-message guard, and a reusable `Banner`.

---

## 1. Inventory — every success/error UI pattern, before this work

### 1a. The existing toast system (replaced in place)

| file | what it was |
|---|---|
| `resources/js/lib/toast.ts` | `toast(message, { tone, action, timeout })`. **Three** tones — `neutral`, `success`, `danger`. Timeouts hardcoded in the module: `0` with an action, `6000` for danger, `2600` otherwise |
| `resources/js/Components/ui/Toaster.vue` | White card, `border-rule` or `border-danger`, plus a coloured dot per tone. `aria-live="polite"` on the container and nothing assertive for failures |
| `resources/js/Layouts/AppLayout.vue:221` | `watch(() => page.props.toast, …, { immediate: true })` — the server flash consumer |
| `resources/js/Pages/Dev/Components.vue:501` | A four-state specimen: Neutral / Success / Danger / With an action |

Client call sites: `BookingLink.vue:16,18`, `CalendarFeedRow.vue:34,77,102`,
`Settings/Calendar.vue:42,44`, `Settings/CalendarSync/Index.vue:77`,
`SuperAdmin/Index.vue:208,210`, `Diary/Index.vue:253`, `AppLayout.vue:224`.

### 1b. Ad-hoc patterns sitting *alongside* the toast system

| file | pattern |
|---|---|
| `CalendarFeedRow.vue:22,32-34,134` | A `copied` ref flipping the Copy button's own label to "Copied" for 1600ms — **and** firing a toast for the same event. Two notifications for one action |
| `CalendarFeedRow.vue:25,168-176` | A `failed` ref rendering a `Callout tone="danger"` with its own "Try again" `QuietAction` — a bespoke error panel with a retry, next to a toast system that already had actions |
| `CustomerNotesPanel.vue:19,74` | `SaveState` fed a `savedAt` timestamp: "Saving… / Saved / Saved 2 minutes ago". A transient confirmation, and a *second* one — `CustomerController:154` already flashes `'Note saved.'`, so a save produced both |
| `Public/ManageIsland.vue:65-69,209-214` | `error` + `notice` + `retry` refs, rendered as a bespoke `role="alert"` block with its own "Try again" `Button`. The whole cancel/reschedule flow's messaging |
| `Layouts/AppLayout.vue:287-340` | **Seven** bespoke banner strips, each repeating `border-b border-b-rule px-4 py-2 text-13 md:px-8` by hand: email verification, trial, read-only, and four SMS states |
| `Components/BetaSandbox/Banner.vue:55` | An eighth, with the same class list plus `flex flex-wrap items-center gap-3` |

### 1c. Found by search, **not** in the brief's list

| file | pattern | outcome |
|---|---|---|
| `Pages/Onboarding/Index.vue:424,443,467` | A `copyState` ref — `'idle' \| 'copied' \| 'manual'` — swapping a button's label between "Copy", "Copied" and "Selected". No toast at all, on a page with no `AppLayout` and therefore no toast host | **replaced** (partly — see §3) |
| `Layouts/AppLayout.vue:221` | The flash consumer was a `watch` on a prop, which compares by value. Two identical consecutive flashes fired **once** | **fixed** — see §2c |
| `Pages/Settings/Sandbox/Index.vue:94-99` | Reads `page.props.toast` and renders it *again* as a per-panel `outcome`, so a sandbox action shows a toast and an inline copy of the same sentence | **flagged** — see §3 |

### 1d. Deliberately not alert systems (verified, then left)

- `ui/Callout.vue` and its 14 consumers. Its own docblock: *"An inline prompt where the thing it is about lives — 'Stripe isn't connected' on the payments screen, not a banner following her around the app."* Not transient, not a notification.
- `ui/FieldError.vue`. `lib/toast.ts`'s own docblock already stated the rule: *"A toast is a receipt, never the only place an error appears — field errors go inline."*
- `ui/SaveState.vue` in `Availability/Index`, `Settings/Index`, `Settings/Loyalty`. A dirty/saving/saved indicator bound to a settings form's state, not an event notification.
- `Auth/Login.vue` and `Admin/Login.vue`'s `ed-alert` classes. Form-level auth errors on the editorial auth layout, inline with the form.
- `Public/BookingIsland.vue` and `Public/OfferIsland.vue`'s `error`/`notice`. The brief named the cancel/reschedule flow; these are the booking and offer flows, whose messages are load-bearing page content.

### 1e. A false lead, so it is not mistaken for a finding

`npm run check:components` reports `7 <copied-control>` in
`Settings/Billing/Index.vue`. That rule is about **copy-pasted CSS class lists**,
not clipboard "Copied" labels — `scripts/check-components.mjs:110`. It is one of
the three pre-existing failures in the audit's decision table and has nothing to
do with this work.

---

## 2. What was built, and what each inventoried item does now

### 2a. The system

| file | role |
|---|---|
| `resources/js/lib/toast.ts` — rewritten | `toast.success(message)` and `toast.error(message, { actionLabel, onAction })`. Nothing else. Two tones, `success` and `error`. Plus `useToasts`, `dismissToast`, `clearToasts`, `configureToasts` |
| `resources/js/Components/ui/Toast.vue` — new | One toast. Solid fill, no border, no shadow, 6px radius |
| `resources/js/Components/ui/ToastContainer.vue` — new | The stack, `Teleport`ed to the body, mounted once per shell |
| `resources/js/Components/ui/Banner.vue` — new | The one banner strip |
| `resources/js/Components/ui/Toaster.vue` — **deleted** | |
| `config/ui.php` | `toast_duration_ms => 4000` |
| `app/Http/Middleware/HandleInertiaRequests.php:123` | shares it as `ui.toast_duration_ms`, the same way `mobile_breakpoint` already was |

Measured in the browser, not asserted from the source:

| | value | token |
|---|---|---|
| success fill | `rgb(24, 23, 20)` | `--ink` `#181714` |
| error fill | `rgb(168, 87, 41)` | `--accent` `#A85729` |
| foreground, both | `rgb(252, 251, 249)` | `--paper` `#FCFBF9` |
| radius | `6px` | |
| border width | `0px` | |
| box-shadow | `none` | |

There is no third tone available to call: the store's `ToastTone` is
`'success' | 'error'` and `push()` is private, so a warning/info variant cannot
be added by a call site. A test asserts the set of tones a full run can produce.

### 2b. Every inventoried item, before and after

| item | before | after |
|---|---|---|
| Calendar Sync copy | button label became "Copied" for 1600ms **and** a toast | `toast.success('Link copied')`. The button is permanently named "Copy" |
| Calendar Sync copy failure | `toast(…, { tone: 'danger' })` | `toast.error(…)` |
| Calendar Sync regenerate success | `toast('Link regenerated — the old link stopped working.')` | `toast.success('Link regenerated — the old link stopped working')` |
| Calendar Sync regenerate failure | a `Callout tone="danger"` on the row, with its own "Try again" | `toast.error(same sentence, { actionLabel: 'Try again', onAction: ask })`. `Callout` import dropped from the file |
| Client History note save | `SaveState` "Saved" **plus** the server's `'Note saved.'` toast | the server flash only. `SaveState` removed from this panel; the **"Last edited by X · date" line is untouched** |
| Client History note failure | inline text under the field only | still under the field, **and** `toast.error(message, { actionLabel: 'Retry', onAction: save })` |
| Reschedule confirmed | `notice` ref rendered under the heading | `toast.success('Moved. We've sent you a new confirmation.')`. Content identical |
| Slot lost during reschedule | `error` ref | `toast.error('That time has just gone. Here is what is still free.')`, and the picker still reloads underneath it |
| Dead booking link | `error` ref | `toast.error('This booking link is no longer active.')` |
| Reschedule/cancel indeterminate | `error` + `retry` + a bespoke "Try again" button | `toast.error(same sentence, { actionLabel: 'Try again', onAction: … })`. Content identical |
| Availability load failure | `error` + `retry` | `toast.error(…, { actionLabel: 'Try again', onAction: loadDays })` |
| Booking link copy (`BookingLink.vue`) | `toast('Copied.')` | `toast.success('Link copied')` |
| Super admin copy | `toast('Copied.')` | `toast.success('Link copied')` |
| Diary booking saved | `toast('Booking saved.')` | `toast.success('Booking saved')` |
| Calendar Sync mode saved | `toast('Saved.')` | `toast.success('Saved')` |
| Onboarding copy link | `copyState` label swap, no toast | `toast.success('Link copied')`, and `ToastContainer` mounted on that page |
| Sandbox strip | bespoke markup | `<Banner row>`, content byte-for-byte unchanged |
| Email verification | bespoke markup | `<Banner message action-label action-href action-method="post">` |
| Trial / read-only / four SMS states | six more copies of the same markup | six `<Banner>` calls |
| 60 server flash sites | a `watch` that dropped repeats | consumed at boot, once per Inertia response |

### 2c. The repeat-flash bug, found and fixed

`AppLayout` consumed the server's `toast` flash with
`watch(() => page.props.toast, …)`. Vue compares a watched getter's value with
`Object.is`, so **two identical consecutive flashes fired once**. Saving the
same thing twice showed one confirmation.

Measured both ways in a real browser, three consecutive saves of the same
record:

```
old (watch on the prop):        1 toast   ["Hours saved."]
new (router.on('success')):     3 toasts  ["Hours saved.","Hours saved.","Hours saved."]
```

Consumption moved to `resources/js/app.ts`, at boot: the initial page's props
once, then `router.on('success')` per response. One consumer for the whole app,
independent of which layout is on screen — which is also what lets the
`Onboarding` page, which has no `AppLayout`, receive a flash at all.

### 2d. `Banner`, and a correction to the brief

The brief describes the existing banner treatment as a *"left-border accent"*.
It is not. All eight strips were a **bottom hairline on paper** — and
`BetaSandbox/Banner.vue`'s own docblock records that a `bg-paper-sunk` fill with
a 2px accent edge was **deliberately removed** for being "a warning strip … for
a condition that is not an error".

So the instruction that governs is the other one in the same section: *"do not
invent a new banner visual style, extend the existing one."*

- `tone="neutral"` is the existing treatment, class for class:
  `border-b border-b-rule px-4 py-2 text-13 md:px-8`. All eight banners render
  exactly as before.
- `tone="attention"` adds `border-l-2 border-l-accent` — the accent edge the
  brief asked for, on the tone that had no prior appearance to preserve. No
  banner in the app uses it yet; it is available and specimened.
- Never a fill, in either tone. A test asserts no `bg-*` class on either.

One regression was caught here and fixed before commit: the space between a
banner's message and its action link disappeared, because Vue condenses
whitespace between two elements. `"…reach you.Resend the email"`. An explicit
`{{ ' ' }}` restores it; verified in the browser as `"…reach you. Resend the email"`.

### 2e. Banner dismissal — nothing to preserve

The brief asks that existing dismiss-persistence be preserved exactly. **There
is none.** Searched and confirmed: no banner has a dismiss control, and the app
contains **zero** uses of `localStorage` or `sessionStorage`, and no
`*_dismissed_at` column or setting. `BetaSandbox/Banner.vue` states why:

> "**It does not dismiss.** … a notice about fake money that can be turned off
> is a notice that will be off on the morning somebody wonders whether a payment
> went through."

So dismissal was **not** added. Adding it would have contradicted a recorded
decision while claiming to preserve behaviour. `Banner` renders no button of its
own; a test asserts that.

---

## 3. Flagged, and deliberately left

**`Pages/Settings/Sandbox/Index.vue:94-99`** — reads `page.props.toast` and
re-renders it as a per-panel `outcome` under the control that caused it, so a
sandbox action produces a toast *and* an inline copy of the same sentence. Left
alone: the inline copy is scoped to one of four panels and says which one acted,
which a corner toast cannot. It is the `Callout` category — an inline result
where the thing it is about lives. It is a genuine double-notification and the
cleanest fix is a dedicated per-panel result string rather than reusing the
flash, which is a change to the sandbox surface rather than to the alert system.

**`Onboarding/Index.vue`'s `'manual'` copy state** — the success half moved to a
toast. The `'manual'` half did not: when the clipboard is unavailable the code
selects the URL text and the label becomes "Selected", with a paragraph naming
`Ctrl`/`Cmd`+`C`. That is an instruction about text that is selected on screen
right now, not a notification of something that happened, and as a toast it
would outlive the selection it refers to.

**`ManageIsland`'s cancel `notice`** — the refund sentence on the "Cancelled"
screen stays inline. The brief lists "cancel confirmed" for the toast, but this
screen's `h1` already *is* "Cancelled", and the sentence under it is the refund
outcome — a persistent record on a terminal screen, the same category as the
"Last edited by X" line the brief explicitly says to keep. As a transient toast
the refund amount would be gone in four seconds with nowhere to read it again.
The reschedule `notice` *did* move, because the heading beside it already
restates the new appointment.

**`ui/Callout`, `ui/FieldError`, `ui/SaveState`, the login `ed-alert`s,
`BookingIsland`/`OfferIsland` inline copy** — §1d, with reasoning.

**`ui/Combobox.vue:128` and `ui/UserMenu.vue:110`** — noticed while working, out
of scope, not touched: both are `absolute z-30` panels of the same shape as the
row-actions menu fixed in part 2. Neither currently sits inside an
`overflow-x-auto` container, so neither shows that bug today. Recorded so the
next person knows the pattern exists in two more places.

---

## 4. Accessibility, and how it was actually checked

Two levels, because a confirmation and a failure are not the same urgency:

- the container is `aria-live="polite"` with `aria-atomic="false"`, so adding a
  toast announces the new one rather than re-reading the whole stack;
- each toast carries its own role — `role="status"` for success, **`role="alert"`
  for error**, which implies `aria-live="assertive"`. The inner role wins for
  that node, so a failure interrupts and a confirmation waits for a gap.

The container is `Teleport`ed to `document.body`, so the live region is not
inside a `Teleport`-less subtree that a page transition could unmount mid-announcement.

Verified rather than assumed:

- Vitest asserts `role` per tone, and the container's `aria-live` / `aria-atomic`.
- Playwright reads them off the live DOM in the browser
  (`alerts.spec.ts`, "the toast lives in a polite live region"), including that
  the container's parent really is `document.body`.
- Measured in a real Chromium session: container `aria-live="polite"`, success
  `role="status"`, error `role="alert"`.

The dismiss control has `aria-label="Dismiss"`. The action control is a real
`<button>` with `min-h-tap`, so it is a 44px target — which is why an error card
with an action is 64px tall rather than 40px.

**Not claimed:** no screen reader was driven. What is verified is the ARIA
contract in the live DOM, not the announcement itself.

---

## 5. The other edge states the brief asked about

| case | behaviour | where asserted |
|---|---|---|
| fired during an Inertia transition | the store is a module singleton and the timers are module-level, so a toast fired before, during or after a navigation survives it; `ToastContainer` re-renders the live stack whenever it mounts | Vitest, "keeps a stack that was fired before it mounted, and across a remount" |
| several failures at once | each is its own item with its own id, message and timer. Nothing coalesces, nothing is de-duplicated by message | Vitest, "keeps every message when several failures land at once" — three distinct per-item failures, three toasts |
| stacking | vertical, `gap-2`, oldest at the top and newest nearest the bottom-right corner, each independently dismissible and independently timed | Vitest, "stacks several toasts, oldest first…" and "times each toast independently"; screenshotted at three-deep |
| very long message | `min-w-0 flex-1 break-words` in a `w-80 max-w-[calc(100vw-2rem)]` card. Never wider than the viewport | Vitest, "wraps a very long message…"; Playwright asserts the card's right edge is inside the viewport |
| no message | `push()` returns `null`, renders nothing, and `console.warn`s under `import.meta.env.DEV` only | Vitest, "refuses an empty message and warns while developing" — covers `''` and `'   '` |
| action label with no handler | treated as no action, so the toast still auto-dismisses instead of hanging forever with a dead control | Vitest, "treats an action label with no handler as no action at all" |
| error with an action | never auto-dismisses. Advanced ten durations, still on screen | Vitest, "never auto-dismisses an error that carries an action"; Playwright waits 6s on a real failure |
| error without an action | auto-dismisses on the config duration | Vitest, "clears an error that has nothing to act on" |

---

## 6. Test coverage

### Vitest — `tests/js/toast.test.ts`, new, 30 tests

10 on the store, 7 on `Toast.vue`, 5 on `ToastContainer.vue`, 8 on `Banner.vue`.
They cover the tone mapping, the absence of a third tone, no-border/no-shadow,
the ARIA roles, the action control, wrapping, stacking, independent timing,
survival across a remount, the empty guard, the config duration, and both banner
tones with the no-fill and no-dismiss-control assertions.

`Teleport` is stubbed globally in `tests/js/setup.ts`; this suite opts back out
per mount, because a stubbed `Teleport` renders the container inline and would
pass while the real thing was broken.

### Vitest — updated

- `Settings/CalendarSync/__tests__/CalendarFeedRow.spec.ts` — the "Copied" label
  assertions became "the button is still called Copy"; the `calendar-feed-error`
  Callout assertions became assertions on the error toast and that **running its
  action reopens the confirm**; `tone: 'danger'` → `tone: 'error'`.
- `Customers/__tests__/CustomerNotesPanel.spec.ts` — the `SaveState` "Saved"
  assertion became "no client-side toast is fired, the flash owns the
  confirmation", plus a new test that a refused save raises an error toast whose
  `Retry` really re-patches.

### Playwright — `tests/e2e/alerts.spec.ts`, new, 4 specs

One per replaced call site, reusing the existing setups (`operator` project's
saved session; `manage-booking.spec.ts`'s `liveToken` helper for the public
island):

1. Calendar Sync copy → one toast, "Link copied", `data-tone=success`,
   `role=status`, computed background is Ink, and the button still says "Copy".
2. The container is a polite, `aria-atomic="false"` live region parented to
   `document.body`, and the toast clears itself.
3. Client History note save → an ink toast; a **second** save raises a **second**
   toast (the repeat-flash regression guard), and the "Last edited by" line
   updates rather than disappearing.
4. A cancel forced to 500 → a terracotta toast, `role=alert`, "still booked",
   still on screen after 6s, and clicking its "Try again" issues a second real
   request. Asserted by counting intercepted requests, not by looking at the DOM.

### Suites

| | Result |
|---|---|
| Pest | **1162 passed**, 0 failed |
| Vitest | **254 passed**, 0 failed (210 at the start of this session) |
| `vue-tsc` | clean |
| `check:php` (Pint) | clean |
| `check:design` / `check:contrast` / `check:name` | clean |
| `check:components` | the same 3 pre-existing files from the audit's decision table |

### Playwright — one full pass, after all three parts

Run the way the seed requires: `./scripts/e2e-setup.sh`, `npm run build`, then
exactly one foreground `./scripts/e2e-playwright.sh`.

| | Result |
|---|---|
| Full pass | **93 passed**, 13 failed |
| The 13 | `auth` 7, `register` 5, `marketing` 1 — the same 13 named in `docs/audit-report-2026-09-10.md` and in the previous fix report. Stale baselines from the auth/onboarding redesign. None is on a surface this work touches and none is new |
| Passed before this session | 87. 93 − 87 = the 6 specs added here: 4 in `alerts.spec.ts`, 2 in `screens.spec.ts` |
| `alerts.spec.ts` | **4 passed**, confirmed again by name against a fresh seed |
| `screens.spec.ts` row-actions | **2 passed**, confirmed again by name against a fresh seed |

Two new baselines were written on their first run — `row-menu-time-off.png` and
`row-menu-overdue.png` — which Playwright reports as a failure by design. They
were captured against a freshly seeded database, before `slot-race` mutates it,
and then re-verified against another fresh seed. No existing baseline was
regenerated: the 13 stale ones are left exactly as the audit's decision table
has them.

---

## 7. One toast, one banner — confirmed by search, not assumption

```
$ grep -rn "Toaster" resources/ tests/
(nothing)

$ grep -n "toast(" resources/js --include vue,ts | grep -v "toast\.success\|toast\.error\|flashToast\|useToasts\|dismissToast\|clearToasts\|configureToasts"
(nothing)

$ ls resources/js/Components/ui/Toast*.vue
Toast.vue  ToastContainer.vue

$ grep -rn "border-b border-b-rule px-4 py-2 text-13" resources/js
resources/js/Components/ui/Banner.vue:26
```

One store, one toast component, one container, one banner strip. The strip's
class list appears in exactly one file. `BetaSandbox/Banner.vue` still exists but
is now a **consumer** of `ui/Banner.vue` holding the sandbox's own content — it
carries no strip styling of its own.

`ToastContainer` is mounted in four places, which is one per shell rather than
one per page: `AppLayout` (every operator screen), `Onboarding/Index` (no
layout), `Public/ManageIsland` (its own `createApp` mount), and
`Dev/Components` (the specimen page).

## 8. Comments

`lib/toast.ts`, `Toast.vue`, `ToastContainer.vue` and `Banner.vue` carry no
comments, at the standing request; the reasoning is in DECISIONS.md, "The alert
system". `app.ts`'s flash consumer is comment-free too, and it is the least
obvious change in this set — the reasoning for it is in DECISIONS.md under "The
server flash was dropping repeats".

No pre-existing comment was removed. `BetaSandbox/Banner.vue` keeps all of its,
including the two inside the markup that moved into the `Banner` slot.

Test files keep explanatory notes, following the precedent set and accepted in
the previous session's `tests/e2e/booking-states.spec.ts`.
