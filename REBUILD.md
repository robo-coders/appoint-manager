# The rebuild

Appoint Manager's interface is being rebuilt surface by surface, in numbered
phases. The user reviews after each one and says when to continue.

**This file exists because the brief did not.** For nine phases it lived only in
chat, which meant the scope of phase 10 had to be *inferred* from a stale label
in `scripts/check-components.mjs` and two deferred items in `DECISIONS.md`. That
inference happened to be right and should not have been necessary. Nothing here
should have to be guessed again.

## How to read it

- **What it covers** is the surface, not the file list. File lists rot.
- **Done / remains** is the state of the working tree, not of the last commit.
  As of 2026-08-27 phases 1–10 are on disk and uncommitted.
- Anything marked **(inferred)** was reconstructed from the repository rather
  than found written down. Treat it as a best reading, not as a record.

The detailed reasoning for every phase is in `DECISIONS.md`, which is the
long-form record; this is the map. `DESIGN.md` is the system those phases build
against.

## The standing rules

They apply to every phase and are not restated in each one.

- **Premium means restraint, not addition.** No gradients, no glass, no blur, no
  drop shadows, no animation, no illustrations, no stock photography, no emoji,
  no icon soup, no centred card floating in grey space, no new dependency, no
  component library. Warm paper `#FCFBF9`, ink `#181714`, 6px radius, hairlines
  instead of borders-and-shadows, mono tabular numerals for anything numeric.
- **One idea per screen**, executed properly. Type doing the work. Generous,
  deliberate whitespace. Every state designed: empty, loading, error, success,
  disabled. Copy written like a person wrote it — no "Oops", no "Something went
  wrong", no exclamation marks.
- **Build from the phase 2 library.** `resources/js/Components/ui/`.
- **Every customer-facing string is built in PHP.** In practice that means data,
  copy that varies, and anything a vertical owns; static UI chrome has stayed in
  the templates since phase 5, which is a reading worth knowing about.
- **`public/mockups/` is binding** where it applies (phases 6 and 7).
- Contrast passes everywhere, verified by tool rather than asserted.
- Anything found broken outside a phase's scope goes in `DECISIONS.md`,
  untouched.
- Every phase ends with `npm run check`, the full Pest suite in parallel,
  `npm run test:e2e`, `vue-tsc --noEmit` and `pint --test`, all green and pasted.

## The phases

### 1 — Foundation · done

The token system. `resources/css/tokens.css` becomes the only file that knows
what this product looks like: colour, type scale, spacing, radius, motion,
density. The dark-and-amber system was retired for light-only warm paper — see
`DESIGN.md`, which states plainly that dark mode is deliberately *not*
implemented and that the previous dark system was never correct. Two values
changed from the brief and both are recorded: `--accent` darkened to clear
4.5:1, and `--ink-3` reclassified from "tertiary text" to disabled-only after
measuring at 2.5:1. `check:design` and `check:contrast` date from here.

### 2 — Components · done

`resources/js/Components/ui/`: one implementation of every control, and
`check:components` to enforce that no screen hand-rolls a second one. Around
forty components. `/dev/components` is the gallery. Five components were built
and then deleted for having no consumer, which is recorded rather than quietly
undone.

### 3 — Per-tenant accent · done

`tenants.brand_colour`, six presets and no free colour picker. PHP never learns
the hex: a choice reaches the browser as `--brand: var(--brand-forest)`, so the
stylesheet stays the only file that knows what forest looks like. Two places on
the booking page use it, and nowhere else does.

### 4 — The appointment suggester · done

`AppointmentSuggester`: the engine that proposes a specific appointment instead
of showing a calendar. Public booking's whole shape depends on it.

### 5 — Public booking · done

`book.` — the three islands (proposal, manage, offer) on the roomy density.
A stranger, on a phone, once, possibly outdoors. The proposal model replaced a
date picker; the fallback picker is behind the quietest control on the page.

### 6 — The admin shell · done

`app.` — the 148px nav rail on paper-sunk, the user control pinned to its
bottom, the dashboard's weighted band and timeline. `public/mockups/dashboard.html`
is the binding target. This is the phase that settled the wordmark lockup.

### 7 — The admin screens · done

Diary, bookings, customers, services, staff, hours, time off, waitlist,
settings, imports, billing, onboarding, profile. `public/mockups/bookings-table.html`
is binding for the table. `check:components` went from 21 pending screens to 3.

### 8 — Auth · done

Login, register, forgot password, reset password, email verification, confirm
password — and the four-step onboarding, because registration is one flow of
five screens rather than one screen. `GuestLayout` stopped being a centred card
and became a full-bleed sheet split by one hairline. `ui/StepProgress` carries
the progress; `App\Support\SetupSteps` holds the five steps once. The final step
takes an optional first appointment so the diary is not empty on day one.

Cleared `Pages/Auth/Login.vue` from the `check:components` list.

### 9 — Super admin · done

`admin.` — the tenant list, the send log, the failures screen and the console's
own login. `data-density='console'` had existed in `tokens.css` since the
density pass and had never been set by anything; it is set now, on the surface's
root. Impersonation is the dangerous action and looks it: `danger` in the row
menu, behind a confirm that names the salon *and* the person.

Cleared `Pages/SuperAdmin/Index.vue`. Also cleared four items `DECISIONS.md` had
queued for this phase: the `ImpersonationController::stop` Inertia redirect, the
console's logout pointing at the app's route, the super-admin billing-lock
bypass, and a guest on an admin route being sent to the operator's login.

### 10 — The surfaces the app sends outward · done

The two customer-facing surfaces that are not screens in the app.

**Error pages.** `resources/views/errors/` did not exist, so every 403, 404,
419, 429, 500 and 503 was the framework's stock grey page. All six are ours now
and all six are *audience-aware*: an operator on `app.`, a customer on `book.`
and a stranger on the marketing host get different wording and different ways
out, from `App\Support\ErrorPage`. 419 stores the page you were on so signing in
returns you there. 503 renders with the database down and the Vite manifest
missing — no query, no manifest, no Inertia — which is verified in tests and was
checked by hand against a stopped MySQL.

**Mail templates.** The seven transactional emails came off stock
`<x-mail::message>` markdown onto a table-based, inline-styled shell that
survives Outlook, with an explicit dark palette (email is repainted by the
client whether or not we have an opinion) and a real plain text part for every
one. `App\Support\MailCopy` holds the copy once so the HTML and text parts
cannot drift.

Also cleared here: the bfcache `Cache-Control` item, and the screenshot
baselines that used to rot at midnight.

### 11 — Marketing · not started

The Blade marketing site at the root domain: home, pricing, and the vertical
pages. It is deliberately not Vue, so that vertical copy such as dog grooming
never lands in the admin SPA. `marketing/partials/cta.blade.php` is the last
entry on the `check:components` list and this phase clears it.

### 12 — Unknown *(inferred)*

**Nothing in this repository describes a phase 12.** The number appears only in
the instruction "do not begin phase 11" bounding earlier work, and in the
phrase "phases 11 or 12" in the phase 10 brief. It is recorded here as a number
that has been used, not as a phase with a scope. Whoever defines it should
replace this paragraph.

## The `check:components` allow-list

`scripts/check-components.mjs` refuses any screen that hand-rolls a control the
library owns. Screens still queued are listed there with the phase that clears
them, and the list only ever shrinks — `MAX_PENDING` is a ceiling that must be
lowered, never raised.

| Screen | Cleared by |
|---|---|
| `resources/views/marketing/partials/cta.blade.php` | phase 11 — marketing |

`MAX_PENDING` is **1**. It has gone 21 → 18 → 17 → 3 → 2 → 1.

One rule is scoped rather than pending: the `table` rule does not apply to
`resources/views/mail/`, because `ui/Table` is a Vue component and Outlook
renders with Word's engine. Nested tables are the only layout email has. Every
other rule still applies there.

## Where the gates are

| Gate | What it protects |
|---|---|
| `npm run check:design` | No off-token value anywhere under `resources/`, plus the mockups' copied token blocks and the `theme-color`/manifest mirrors, all verified against `tokens.css` |
| `npm run check:contrast` | Every text colour against every surface it lands on, including all six tenant brand presets |
| `npm run check:components` | No screen hand-rolls a control the library owns |
| `npm run check:name` | The old product name is gone |
| `npm run test:unit` | Vitest — component behaviour in jsdom |
| `npm run check:php` | Pint |
| `npm run test:php` | Pest, in parallel |
| `npm run test:e2e` | Playwright against a real MySQL, on a frozen clock |

Two things the gates deliberately **cannot** do, both recorded in
`DECISIONS.md`:

- A screenshot cannot tell `--paper` from `--white`. They are a YIQ delta of 8.3
  apart, under every per-pixel threshold that still tolerates font rasterisation
  on another machine. Surfaces are asserted with `expectSurface()` in
  `tests/e2e/support.ts` instead.
- `check:design` cannot see a colour generated by a build plugin. The Tailwind
  forms plugin's blue focus border was invisible to it for nine phases and is
  asserted in a real browser instead.
