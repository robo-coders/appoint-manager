# Decisions

A running record of choices that are not obvious from the code, and of the
reasons a later reader would otherwise have to guess at.

This file is referenced from `scripts/check-name.mjs` and from comments in
`bootstrap/app.php`, `scripts/check-design-tokens.mjs` and `scripts/e2e-setup.sh`,
but it was not in the tree when the console redesign below was made. It is
restarted here rather than reconstructed: the entries those comments cite are
quoted in full where they are cited, and inventing the rest would be worse than
an honest gap.

---

## 2026-09-15 — The console shell: account menu, logout confirmation, stat strip

Sections 1a, 1b and 1e of `.design/mockups/admin/Admin Dashboard Mockups.dc.html`,
built for `admin.diarydesk.com` only. 1c and 1d were out of scope and are not
implemented.

### The mockup is a layout spec, not a colour spec

The reference is a dark Nocturne export — its own tokens, 8/14px radii, four
shadows and `text-transform: uppercase` throughout. None of that crosses over.
Every one of the three sections is rebuilt on the Editorial A tokens already in
`resources/css/tokens.css`: paper, ink, one terracotta accent, 6px radius, no
shadow but the focus ring, hairline rules.

Two of the mockup's instructions are things this system explicitly forbids, and
the design-token gate is the thing that forbids them:

- **All-caps labels.** `check:design` fails on `uppercase` and on
  `text-transform: uppercase`, by the rule "sentence case everywhere". The rail's
  "Platform" heading uses `.eyebrow`, which is `font-variant-caps: all-small-caps`
  — a different letterform rather than a shouted one, and already the house
  answer for "the label above a section, a nav group or a column of figures".
  It reads as the mockup's caps and passes the gate.
- **Card-shaped stat tiles.** The mockup draws four filled, shadowed, 10px-radius
  cards. `ui/Stat` separates figures with a hairline and a left rule instead,
  which is the same decision the rest of the product already made. The strip is
  four `Stat`s, not four new cards.

### The shell is shared, so only the console half moved

`AppLayout` and `NavRail` serve both the operator app and the console, switching
on `page.props.tenant`. The redesign was scoped to the console, so:

- `NavRail` gained one `admin` flag. It renders the new `ui/AccountMenu` on the
  console and keeps `ui/RailUserMenu` — with its appearance switcher and
  impersonation block — for the operator app. `RailUserMenu` is **not** deleted;
  it is still the operator app's account menu and deleting it would have
  restyled a surface this change was scoped out of.
- The "Platform" group heading and the rail counts needed no `NavRail` change at
  all. It has supported `group` and `count` on a link since the operator app
  grew them; the console's links simply never set them.
- The active-item treatment — left accent bar plus `bg-accent-tint` — is the
  existing one, unchanged. The mockup asks for exactly what was already there.

The one place this was got wrong first is worth recording. Making the
unconfirmed-email banner dismissible added a close control to **both** surfaces,
which changed that banner's height and shifted all sixteen operator screenshot
baselines. The dismiss is console-only now. The operator app's copy of that
banner is load-bearing in a way the console's is not: an unconfirmed salon
address is why a client's reply goes nowhere, and Overview already carries it as
a task.

### Notice dismissal is session-scoped, and that is the feature

`POST /admin/notices/{notice}/dismiss` writes an allow-listed key into the
session. Not a column, and not `localStorage`:

Every banner this backs is conditional on something still being wrong. The
lifetime that matches is "stop repeating it on every page of this visit", not
"never again" — the next visit re-asks while the condition holds and stops on
its own when it clears, which a stored flag has to be manually reset to get.
The allow-list exists because the key arrives in a request body and an unbounded
one lets a client grow the session cookie a kilobyte at a time.

The banner hides on the server's answer, never on the click. A dismiss that does
not land leaves the banner where it was and raises an error toast.

### Logging out is an XHR, not an Inertia visit

`AccountMenu` posts to `admin.logout` through `window.axios` and then does a full
page load to the login route. An Inertia visit was tried and is wrong here: a
visit that never lands raises Inertia's own exception overlay *over* the dialog,
where what is wanted is the dialog staying open with the reason in a toast and
the second attempt one click away. A full load on success, because the session
is gone and every cached page prop in the client belongs to it.

`ui/ConfirmDialog` is reused rather than forked — it already had the focus trap,
Escape-to-cancel and the loading state. It gained an optional `icon` slot and an
`accent` tone that maps to the filled terracotta button, both additive.

### Two things the e2e suite taught, both about a shared session

The console project replays one stored cookie from `console.setup.ts`, and
`Auth::logout()` invalidates that session server-side. A logout spec sharing the
cookie signs the whole project out from under itself — it read as nine unrelated
specs landing on the login page. The real-logout test now runs on its own
session. For the same reason the notice specs reset `dismissedNotices` in the
rewritten Inertia payload: dismissal is session-scoped, so a test that dismisses
hides the banner from every test after it.

Separately: `throttle:admin` is 60/minute keyed by user, and the suite's clock is
frozen, so that is a budget **per run** rather than per minute. The console
project went from 4 specs to 22 and now sits closer to it. `scripts/e2e-setup.sh`
clears the cache; running the console project twice without reseeding in between
now exhausts the limiter and fails as a 429 page.

### Numbers are counted, never carried

The stat strip is counted in `SuperAdminController::platformStats()`, not summed
from the row list above it. The rows are a view of the tenants — sorted, and
filtered client-side once the toolbar is used — and the stats are statements
about the platform; deriving one from the other is how a strip starts
disagreeing with itself the moment somebody types in the search box.

"Today" is the platform's day, not each salon's: tenants carry their own
timezone and a per-tenant day boundary would make that number a sum of
twenty-four different days. Send failures is the sum of both lists the Failures
screen shows, so the card and the screen it sits above cannot disagree.

### What was not built, and was not faked

- **"Unsaved edits are kept for 24 hours."** There is no draft retention
  anywhere in the backend. The copy says what is true instead: anything typed
  and not saved is lost.
- **Platform settings, Docs, a version string.** No route, no URL, no version
  exists for any of the three. They are left out of the menu rather than pointed
  somewhere plausible. The footer carries the environment alone.
- **"+6 wk" on Live tenants.** Nothing records when a tenant's page went live,
  so the week-over-week delta cannot be computed. No delta is shown.
- **"Suspended" as a filter segment.** A word from a different billing model.
  This product says "Payment failed", "Cancelled" or an expired trial, and
  `needs_attention` already groups the three — so the segment is "Needs
  attention", and a copied vocabulary does not put a filter on screen that
  matches nothing.

---

## 2026-09-15 — The console shell, second pass: what the first pass did not draw

The first pass built 1a, 1b and 1e as structure and left five things on the
reference undrawn. They are drawn now, and the reasons the gaps existed are
worth more than the diff.

### Rail icons are the console's, not the rail's

`NavRail` had `navIconFor` wired and an icon for every label, and rendered none
of them at full width: the icon was inside the `collapsed` branch, so it existed
only as the 56px rail's replacement for the words. `tests/js/navrail.test.ts`
asserted that — "shows the words, and no icons" — so the gap was a decision, not
an oversight.

It stays a decision for the operator app. Its rail is twelve items in three
groups, and putting an icon on each would restyle a surface this work was scoped
out of. The console's four items get them, gated on the `admin` flag the rail
already carries for the account menu. Both behaviours are now asserted.

### `ui/Stat` used `ui/Label`, which is the other label

`.eyebrow` and `.caption` are both in `base.css` with a comment saying they are
not interchangeable — the eyebrow is "the label above a section, a nav group or
a column of figures", the caption is a field label in sentence case. `Stat`
reached for `ui/Label`, which draws `.caption`, so the figure captions rendered
in sentence case while the rail heading beside them rendered in small caps. The
strip is a column of figures; it takes the eyebrow. `ui/Label` is untouched and
still the field label.

### The identity column carries a chip

Two letters from the words a person would say — "Willow & Wolf" is WW, not W& —
over the booking link with its scheme stripped. Neutral `ink-tint`, not the
accent: the account avatar is the one tinted chip on the screen, and five more
in a column would spend the accent on the least important thing on it.

### The unconfirmed-email banner was never broken

`NoticeController`, the `dismissedNotices` share and `Banner`'s dismiss all
work, and three specs have covered them since the first pass. The banner did not
appear because **the seeded console admin has a confirmed address**, which is
the correct behaviour of a notice gated on an unconfirmed one. What was missing
was a screenshot proving it, so the visual-check capture now drives the edge
state the way the rest of this repo does — by rewriting the Inertia payload on
the way in, leaving the seed alone.

### The breadcrumb goes above the notice

The reference's own note: "The stray notice line becomes a contained banner …
inside the content column instead of floating above it." The crumb is the
shell's permanent chrome and a notice is passing traffic, so the crumb is lifted
above the banner stack. Only the console has a crumb, so only the console moves.

### `product.version`

The account menu's footer is the build beside the environment, and the build is
read from the deploy (`PRODUCT_VERSION`, default `dev`) rather than committed.
A version in a config file is a version somebody forgets to bump.

### Still not drawn, and why

The reference's account menu has a **Docs** row and a **Platform settings** row.
Neither has a destination in this product — there is no documentation site and
no platform-settings screen, and a menu row that goes nowhere is worse than an
absent one. They want a product decision, not a component change.

## 2026-09-15 — The console shell, third pass: six regressions from the second

### The environment is named once, in the breadcrumb

The second pass put `app.env` in two places: a `Badge` in the console crumb and
a bare word in the account popover's footer. Two renders of one value is one too
many, and the popover's copy sat immediately above the identity row with no
container of its own, which is what read as stray debug text overlapping the
account block. The crumb keeps it — it is the chrome that says *where you are*,
and an environment is part of that. The popover's footer keeps the build only,
labelled, so `dev` is no longer an unexplained word in a corner. `environment`
is gone from `NavRail` and `AccountMenu` entirely rather than left unused.

### The current nav link is the longest match, not the first

`isCurrent` was a prefix test, and the console's Tenants link is the surface
root — `/admin`. So on `/admin/failures` it matched Tenants *and* Failures: two
accent bars in the rail, and a crumb reading "Platform › Tenants" because
`links.find()` returns whichever matched first. The layout now resolves one
winner — the longest matching path — and `isCurrent` compares against it, so
the rail's accent, `aria-current` and the crumb cannot disagree by
construction.

### A tenant with no name says so

`RegisteredUserController` creates the tenant with `'name' => ''` and onboarding
asks what the salon is called afterwards, so **an unnamed tenant is a legitimate
state, not corrupt data** — every account that signed up and stopped is one. The
console had no answer for it: the title line rendered empty, which left the
booking URL on the line below looking like the salon's name, and the initials
chip fell through to a literal `?`.

The empty name now resolves to "Unnamed salon" in italic `ink-2` — distinct from
a real name at a glance, and honest about what the row is. The slug is *not* used
as the label: the mono line directly beneath it is the booking URL, which already
contains the slug, so a slug title would be the same string twice. The chip takes
a neutral `Store` mark instead of letters. `initials()` no longer returns `?` for
anything — it returns an empty string and the caller draws the mark, which is
also what `AccountMenu`'s trigger now does for a person with no readable name.

The raw `name` is still what the search and the CSV export read through
`salonName()`, so "unnamed" finds these rows and the export says what the screen
says. The table's Salon column sorts on the resolved label rather than the raw
one, so the unnamed rows group instead of sorting to the top on an empty string.

### One identity block, and the popover is the expanded one

The popover repeated the whole identity — chip, name, email, role pill — while
the trigger it opens from sat directly beneath it showing chip, name and role
again. The trigger is the persistent one, so the popover's header is gone and
what is left is the one thing the trigger cannot show: "Signed in as
<email>". Name, role and chip exist exactly once in the DOM whether the menu is
open or shut.

### `Comped` is a real state and now has a tone

`SuperAdminController::state()` returns it for `is_comped`, `super-admin.comp`
sets it, and the SlideOver has always been able to. It is not seed litter. The
mock's state set is from a different billing model — there is no "Suspended"
here — so rather than keep a ternary that sorted eight backend states into three
tones, the states are mapped to tokens explicitly: `Subscribed` confirmed,
`Comped` accent, `Trial` pending, `Payment failed`/`Cancelled`/`Trial over`
cancelled, `Paused`/`No plan` neutral. Comped is the one non-paying state that
is deliberate, so it gets the accent rather than being drawn as if it were
subscribed. A state added later falls back on `needs_attention`.

### `throttle:admin` reads its ceiling from config

`RateLimiter::for('admin')` was a hardcoded 60 a minute keyed by admin id, and
the throttle buckets live in the cache, so they survive between Playwright runs.
The console project now drives 36 specs through one signed-in admin and a run on
top of an earlier partial one 429s partway through — 14 specs failed on "Too many
tries, too quickly", with the 429 page in every error context, which reads
exactly like a UI regression and is not one.

The number now comes from `config('admin.rate_limit_per_minute')`, default 60,
so production is unchanged, and `playwright.config.ts` raises it for the e2e
server the same way it already passes that server its own database and Stripe
keys. `SurfaceRoutingTest` pins the behaviour by setting the config to 2 and
expecting the third request to be refused — it fails against a hardcoded limit,
which is the point of it.
