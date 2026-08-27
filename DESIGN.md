# Design

Appoint Manager is appointment software for small businesses that lose money
when people do not turn up. It has two audiences and three surfaces, and it
should read as quiet, precise and expensive — the register of Mercury, Ramp,
Linear and Stripe. Not a dashboard, not a template, not dark.

Every value in the product comes from `resources/css/tokens.css`. If a value is
not in that file it does not belong in a template.

`npm run check` runs three gates, all of which fail the build:

- `check:design` — no off-token class or raw colour anywhere under
  `resources/`, stylesheets and SVG included, plus the handful of values that
  must restate a token (the `theme-color` meta, the manifests) checked against
  `tokens.css` so they cannot drift from it
- `check:contrast` — every text colour against every surface it lands on, the
  `brand` default, and all six tenant brand presets, all read from `tokens.css`
- `check:components` — no screen hand-rolls a control the library owns

---

## Light only

**Dark mode is deliberately not implemented.** The previous dark system was
never correct, and light is the design target. The dark branches were removed
rather than left broken. There is no `prefers-color-scheme` branch and no
theme toggle; adding one later means adding a second palette, not un-breaking
this one.

---

## Colour

### Surfaces

Depth is a tonal step plus a hairline. **No shadows anywhere except the focus
ring. No gradients, no blur, no glass.**

| Token | Value | Use |
|---|---|---|
| `paper` | `#FCFBF9` | page |
| `paper-sunk` | `#F4F2EE` | summary bars, nav rail, inset regions |
| `white` | `#FFFFFF` | inputs, unselected slots |

### Ink

**Ink is the primary action colour.** Buttons, selected states and the current
selection are all ink with white text. A black button on warm paper is what
makes this read expensive; nothing else does that job.

Ratios are against `paper`, computed by `check:contrast`.

| Token | Value | Ratio | Use |
|---|---|---|---|
| `ink` | `#181714` | 17.3:1 | primary text, primary fill |
| `ink-2` | `#6F6D66` | 5.0:1 | secondary text — **this is the caption colour** |
| `ink-3` | `#A3A099` | 2.5:1 | **never text.** Disabled controls only |
| `ink-4` | `#CFCCC4` | 1.6:1 | **never text.** Struck-through slots, rules |

The brief listed `ink-3` as "tertiary text, captions". Measured, it is 2.5:1 —
unusable as text at any size. Captions use `ink-2`. `ink-3` and `ink-4` survive
only for disabled and struck-through states, which WCAG exempts from contrast
requirements.

### Rules

`rule` (8%), `rule-strong` (16%, input and button borders), `rule-hover` (34%).
Hairlines carry the structure. Never a solid grey line.

### The accent

`accent` `#A85729` — the product's own terracotta. **At most one per screen**,
and only where it carries meaning: a cancelled slot needing action, a "first
available" marker, a waitlist count. If a screen has two, one is wrong.

The brief specified `#B5612F`, which measures 4.31:1 on paper, 3.98:1 on
paper-sunk and 4.45:1 on white — under 4.5 on all three. It is used as *type*,
so it was darkened to the nearest value clearing 4.5:1 on every surface.

The accent is also the focus ring, which is the one place it may repeat.

**Selection is ink on white, not accent.** `::selection` fires on every screen
at once, which is the exact opposite of at most one per screen, and "you
dragged over some words" is not one of the three meanings the accent is
rationed for. It was `accent-tint` in `base.css` and ink in the mockups; ink
won.

### Status

The admin app is monochrome: status reads from ink weight, not hue. Only a
cancellation earns colour (`danger` `#A8342C`, 6.4:1). **Meaning is never
carried by colour alone** — every badge carries its label, and struck-through
slots carry `aria-disabled`.

Staff colours are per-user *data*, not tokens, and are the one legitimate
colour outside this system.

---

## Per-tenant brand

`tenants.brand_colour`, nullable, defaulting to ink. **Six presets, no free
colour picker** — a hex field guarantees someone ships neon yellow on white and
it is *our* product that looks broken.

| Token | Value | `brand-fg` on it | On paper |
|---|---|---|---|
| `brand-forest` | `#2F5D4A` | 7.5:1 | 7.3:1 |
| `brand-plum` | `#7B3448` | 8.7:1 | 8.4:1 |
| `brand-navy` | `#24415F` | 10.5:1 | 10.2:1 |
| `brand-ochre` | `#8A5A1E` | 5.9:1 | 5.7:1 |
| `brand-slate` | `#414A52` | 9.0:1 | 8.7:1 |
| `brand-clay` | `#8C4A32` | 6.7:1 | 6.5:1 |

All six live in `tokens.css` and are verified by `check:contrast`, which reads
them from there. They used to be a private copy inside the checker, which meant
the values that shipped were never the values that were checked.

`--brand` and `--brand-fg` are the pair a surface actually consumes;
`--brand` defaults to `ink` and `--brand-fg` to `white`. `bg-brand`,
`text-brand-fg` and `AppLogo tone="brand"` all resolve through them, and all
three were dead until the tokens existed.

**It appears in exactly two places on the public booking page:** the salon's
initial mark, and the primary button. Nowhere else — not links, not borders,
not selected slots. Selected slots stay ink.

**The admin app stays monochrome.** She is in it forty times a day; a colour
chosen for her customers becomes noise as chrome.

---

## Type

- **Text:** Geist, **self-hosted**, at 400 and 500 only. Three `woff2` files in
  `resources/fonts/`, `@font-face`d with `font-display: swap` at the top of
  `resources/css/base.css`, and the two sans weights preloaded in
  `partials/head.blade.php`. There is no font host: no `preconnect`, no
  third-party stylesheet, and `font-src` in `SecurityHeaders` no longer names
  one. Inter has been dropped from the stack with it — it was there as a second
  web font in case Geist failed to fetch from a CDN, and the fallback after
  Geist is now the system grotesque.
- **Weights: 400 and 500 only.** Never 600, never 700.
- **Numbers:** Geist Mono with `font-variant-numeric: tabular-nums` — times,
  prices, durations, counts and IDs. **Never mono for prose.**
- **Scale: 12, 13, 14, 15, 17, 20, 24, 34.** Nothing else.
- **Tracking:** `-0.03em` at 34, `-0.025em` at 24, `-0.02em` at 20,
  `-0.015em` at 17, normal below. Baked into each size. The two larger values
  were loosened once Geist was actually loading — see `DECISIONS.md`.
- **Sentence case everywhere.** No Title Case, no ALL CAPS labels, no
  exclamation marks. `check:design` fails on `uppercase`.

The previous system's uppercase letterspaced mono label is retired — it was a
distinctive move, and it is now explicitly forbidden. Captions are `.caption`:
13px, `ink-2`, sentence case, normal tracking.

---

## Geometry

- **Radius: 6px on everything.** No exceptions, no pills, no 12px cards. Only
  `rounded-none` exists alongside it, for table rows and list items.
- **Containment is a signal — spend it once.** Only the selected item in a set
  gets a fill or a border. Unselected items sit on the page.
- **No nested cards.** If a card contains a card, remove one.

## Space

4px base: 4, 8, 12, 16, 24, 32, 48, 64. Off-scale values (5, 7, 9, 10) are not
available. Chrome dimensions are named tokens so a layout width never looks
like a spacing value, and every one of them is a multiple of 4:

| Token | Value | Use |
|---|---|---|
| `rail` / `rail-collapsed` | 148 / 56 | the nav rail |
| `control-h` / `row-h` | per density | control and row height |
| `badge-h` | 20 | status badge |
| `skeleton-h` | 8 | one loading bar |
| `col-when` | 152 | bookings table, date and time |
| `col-time` | 56 | an agenda's bare `HH:MM` |
| `col-staff` | 96 | bookings table, who |
| `col-status` | 132 | bookings table, status badge |
| `col-amount` | 112 | bookings table, money |
| `col-actions` | 56 | bookings table, row menu |
| `sub-indent` | `col-time + space-4` | a sub-line hanging under the text |
| `booking-w` | 440 | the public booking column |
| `topbar` | 56 | the operator app's top bar |

The column tokens exist so a table's loading skeleton can be shaped to the real
table — one bar per column at that column's width — instead of three generic
bars.

`pad-x` is on the grid too — 12 compact, 16 roomy, 8 console. It was 10/14/8
until the components phase, which is when every control that inherits it was
being built anyway.

**Colouring one border side.** `border-rule` and `border-accent` set all four
border colours, so a row that carries a 2px accent left border *and* a hairline
bottom must scope both: `border-l-2 border-l-accent border-b border-b-rule`.
Writing `border-l-2 border-accent border-b border-rule` gives the bottom
hairline whichever colour was declared last, on all four sides. The component
library uses the scoped form throughout, even where only one side has width.

**A chrome utility that names a token nobody defined compiles to nothing.**
`w-sidebar` against a config that defines `rail` is not an error, it is silence,
and the element simply has no width. `check:design` reads the valid names out of
`tailwind.config.js` and fails on any that are not there.

## Motion

Two durations, one curve, and **opacity and border-colour only**:

- `120ms` state changes, `180ms` entrances.
- `cubic-bezier(0.2, 0, 0, 1)`.
- No scale, no translate beyond 4px, no bounce, nothing animating on page load.
- `prefers-reduced-motion` makes everything **instant, not slow**.

## Focus

A 2px accent ring at 2px offset on every focusable element, visible always —
not only on `:focus-visible` for keyboard-critical controls. It is the only
shadow in the product.

---

## Three surfaces, one language

Three products share one codebase. They differ in **density and layout**, never
in visual language.

| | Operator (`app.*`) | Public booking (`book.*`) | Console (`/admin`) |
|---|---|---|---|
| Who | the owner, 40× a day | a stranger, once, on a phone | us, at 2am |
| `data-density` | `compact` (default) | `roomy` | `console` |
| Control height | 36px | 48px | 32px |
| Row height | 34px | 44px | 28px |
| Rhythm | 8px | 12px | 8px |
| Field text | 14px | 15px | 13px |

A surface sets `data-density` **once** on its root and every component follows.
No component takes a size prop for this.

---

## Components

Shared components live in `resources/js/Components/ui/`. **No page component
may contain a hand-rolled input, button, table, modal or menu.**
`check:components` enforces that, with an explicit list of screens still queued
for a later phase; that list only ever shrinks.

The gallery at **`/dev/components`** renders every component in every state —
default, hover, focus, disabled, error, loading, empty — with a density switch
that sets `data-density` on the page root. It is registered only outside
production, and it is not exempt from `check:components`: it passes the same
rule as every other screen.

**The gallery is where components are checked, not the type checker.** Both of
the bugs in the rebuilt `Skeleton` — bars in a colour 1.06:1 from their
background, and a bar sized as a percentage of a zero-width parent — passed
`vue-tsc` and all three gates, and were obvious the moment a browser drew them.

**Every screen exposes four states:** loading (a skeleton shaped like the real
content, never a spinner, appearing only after 200ms so fast actions never
flash), empty (one sentence and one action), error (inline below the field,
linked with `aria-describedby`, in `danger` — never a toast alone), and
populated.

---

## Identity

The mark is a square severed along the 1:2 diagonal — the cut falls twice as
far as it runs, so the two halves are the same shape at different weights. One
primitive, one cut. It reads as a marked square at 16px and as a confident
geometric form at 200px.

`resources/svg/mark.svg` (inherits `currentColor`), `mark-mono.svg`,
`public/icons/`, and `Components/AppLogo.vue` with `size`, `variant`
(`mark` | `lockup`) and `tone` (`ink` | `brand`) props. `brand` is only for the
public booking page, where the mark takes the salon's colour.

---

## Accessibility

- 4.5:1 minimum for all text, **verified by a tool, not asserted**.
- Everything interactive reachable and operable by keyboard.
- Visible focus on everything focusable.
- Modals trap focus and restore it to the trigger on close.
- No meaning by colour alone.
- Semantic HTML: real `<button>`, real `<table>`, real `<label>`.
