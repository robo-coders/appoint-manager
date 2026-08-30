#!/usr/bin/env node
/**
 * Fails if a screen hand-rolls a control the component library already owns.
 *
 * The hard rule: no page component contains a hand-rolled input, button, table,
 * modal or menu. There is one implementation of each of those, it lives in
 * `resources/js/Components/ui/`, and `/dev/components` is where you look at it.
 *
 * This reads Blade as well as Vue. It did not before, and marketing is Blade,
 * so the entire marketing site was unguarded — the check reported "clean" over
 * a tree it had never opened.
 *
 * Blade needs a rule the Vue rules do not, because Blade hand-rolls controls a
 * different way. `marketing/partials/cta.blade.php` is not a `<button>`: it is
 * an `<a>` carrying Button.vue's primary-variant class list, copied by hand,
 * with a comment saying so. The tag rules below cannot see that, and a check
 * that only looks for `<button` would pass the marketing tree on its first run
 * and mean nothing. COPIED_CONTROL is the rule that actually catches it.
 *
 * Screens still queued for a later phase are listed in PENDING with the phase
 * that will clear them. That list only ever shrinks: a file on it that has gone
 * clean is an error (remove it), and MAX_PENDING below is a ceiling that must
 * be lowered, never raised. Adding to the list without lowering the ceiling is
 * how this check stops meaning anything.
 *
 * Run: npm run check:components
 */
import { readFileSync, globSync } from 'node:fs';

/*
 * Screens not yet rebuilt, with the phase that would remove them from this list.
 *
 * **It is empty.** Phase 11 rebuilt marketing and cleared the last entry, which
 * was `marketing/partials/cta.blade.php` — an `<a>` wearing Button.vue's
 * primary-variant utility classes, copied by hand, with a comment saying the
 * classes were copied so the two would not drift. That is the drift the
 * `copied-control` rule exists to catch, and a comment is not a mechanism. The
 * marketing site has one button now, declared once in `resources/css/marketing.css`
 * from the same tokens Button.vue reads, and `copied-control` passes over the
 * whole tree without being weakened to let it through.
 */
const PENDING = {};

/*
 * The ceiling. Every entry in PENDING is a screen that will be rebuilt in a
 * later phase; when one is, delete its line and drop this number to match.
 * A pull request that raises it is a pull request that gives up.
 *
 * It was raised once, 20 -> 21, and this is the record of it: the Blade tree
 * came into scope and brought one already-existing offender with it. Nothing
 * got worse — the check started looking somewhere it had never looked. That is
 * the only reason this number may ever go up, and it did not go up by more
 * than the one file that was found. It goes down from here.
 *
 * 21 -> 18: the three public booking islands, rebuilt on the proposal model.
 * 18 -> 17: the operator shell. The rail is `ui/NavRail` now, so every control
 * in it lives in the library and the gallery can draw all three of its widths.
 * 17 -> 3: the operator app's screens — the diary, every list, settings,
 * imports, billing, onboarding and the weekly hours grid. `CommandPalette`
 * came off this list by moving *into* the library, which is where a global
 * control belongs; it was never a screen.
 *
 * The three that are left each name the phase that clears them, and two of
 * those phase numbers were wrong: super admin is phase 9 and the auth rewrite
 * is phase 8 in the numbering actually in use. Fixed here rather than left as
 * two entries pointing at phases that have been and gone.
 *
 * 3 -> 2: the auth rewrite. `Auth/Login.vue` was on this list for one control —
 * a raw `<input type="checkbox">` wearing `rounded border-rule` by hand, which
 * is a different radius and a different border from every other checkbox in the
 * product. It is `ui/Checkbox` now, and the rest of the auth surface came off
 * `GuestLayout`'s centred card with it.
 *
 * 2 -> 1: super admin. `SuperAdmin/Index.vue` was a hand-rolled `<table>`, five
 * bare `<button>`s per row and two placeholder-only `<input>`s; it is `ui/Table`
 * with the row actions in one `ui/Menu` and the clone form in a `ui/SlideOver`
 * now. `Messages` and `Failures` were not on the list because they hand-rolled
 * nothing — they simply had no design at all, which this check cannot see and
 * phase 9 fixed anyway.
 *
 * 1 -> 0: marketing, in phase 11. The list is empty and the ceiling is zero,
 * which means the next entry anybody wants to add has nowhere to go.
 */
const MAX_PENDING = 0;

// The library itself is where these elements are allowed to exist.
const ALLOWED_DIRS = ['resources/js/Components/ui/'];

const RULES = [
    { id: 'input', re: /<input\b/g, use: 'ui/TextInput, ui/Checkbox or ui/Toggle' },
    { id: 'textarea', re: /<textarea\b/g, use: 'ui/Textarea' },
    { id: 'select', re: /<select\b/g, use: 'ui/Select or ui/Combobox' },
    { id: 'button', re: /<button\b/g, use: 'ui/Button, ui/Menu or ui/MenuItem' },
    { id: 'table', re: /<table\b/g, use: 'ui/Table' },
    { id: 'modal', re: /role="(dialog|alertdialog)"/g, use: 'ui/Modal, ui/SlideOver or ui/ConfirmDialog' },
    // A hand-rolled dropdown is the one that always ships without Escape,
    // without outside-click, and without focus going back to the trigger.
    { id: 'menu', re: /role="menu"|aria-haspopup=/g, use: 'ui/Menu, ui/MenuItem or ui/UserMenu' },
    /*
     * A control that is not wearing a control's tag. An element with a fill, a
     * radius and a tap target is a button whatever element it is written as,
     * and if its classes were typed out by hand they are a second copy of
     * Button.vue that nothing keeps in step.
     *
     * All three signals are required together on purpose: `rounded` alone is a
     * card, `bg-ink` alone is a filled panel, `min-h-tap` alone is a spacious
     * list row. Together they are a button.
     */
    {
        id: 'copied-control',
        re: /class="[^"]*(?=[^"]*\b(?:bg-ink|bg-brand|bg-accent|bg-danger)\b)(?=[^"]*\brounded\b)(?=[^"]*\b(?:min-h-tap|h-control)\b)[^"]*"/g,
        use: 'ui/Button — or a plain link, if it is a link',
    },
];

/*
 * Vue and Blade. `resources/views/**` covers marketing, the public shells, the
 * error pages and the mail templates.
 *
 * The `table` rule does not apply to two Blade trees, and that is a scope
 * decision rather than an exemption. The rule's premise is that there is one
 * implementation of a table and it lives in `Components/ui/` — and `ui/Table`
 * is a **Vue** component. Where there is no Vue, the rule is not asking a screen
 * to use the library, it is asking it not to have a table, which is a different
 * and much worse rule.
 *
 *   - `views/mail/` — Outlook on Windows renders with Word's engine: no
 *     flexbox, no grid, no `max-width` on a `div`. Nested `<table>` is not a
 *     hand-rolled control there, it is the only layout that exists, and a rule
 *     forbidding it would be a rule forbidding email.
 *   - `views/marketing/` — the marketing site is Blade and mounts no Vue at
 *     all, deliberately, so that a vertical's copy never lands in the admin SPA
 *     (REBUILD.md, phase 11). `ui/Table` is therefore unreachable from it by
 *     construction, not by neglect. Three tables on the surface are genuinely
 *     tabular figures — the pricing ledger's two columns, the home page's
 *     recovered-revenue sum, and the trade page's seeded price list — and
 *     DESIGN.md requires a real `<table>` for exactly that. The alternative
 *     tried first was a CSS grid inside a `<dl>`, and it was worse in a way
 *     that is worth recording: with `column-gap` between the label and the
 *     figure, the total's rule was drawn as two separate borders with a visible
 *     notch cut out of the middle of it.
 *
 * This is the narrower of the two ways to write it. Scoping by medium — "Blade
 * cannot use a Vue component" — would exempt all of `resources/views/**`,
 * including the error pages, which have no tables and should keep having none.
 * The two directories are named so that adding a third is a visible decision.
 *
 * Every other rule still applies to both trees, including `copied-control` — a
 * button in an email, and a button on the front page, are each still allowed to
 * be exactly one shape.
 */
const NO_VUE_TABLE = ['resources/views/mail/', 'resources/views/marketing/'];

const files = [...globSync('resources/js/**/*.vue'), ...globSync('resources/views/**/*.blade.php')]
    .filter((f) => !ALLOWED_DIRS.some((d) => f.startsWith(d)))
    .sort();

const rulesFor = (file) =>
    NO_VUE_TABLE.some((dir) => file.startsWith(dir)) ? RULES.filter((r) => r.id !== 'table') : RULES;

/*
 * Prose is not code.
 *
 * These rules look for tag names in raw source, and a comment explaining *why*
 * a screen must not hand-roll a control has to name the control to say anything
 * — `ui/ServiceChoiceList` documents itself with "never a <select>" and was
 * reported as containing one. `check-design-tokens.mjs` has stripped comments
 * since it started reading stylesheets, for the same reason and in the same
 * words; this one had never needed to until a comment said the quiet part.
 *
 * Stripping is safe as well as necessary: a control inside a comment is a
 * control that does not render, so there is nothing here to hide.
 */
const stripComments = (src) =>
    src
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');

let failures = 0;
const stillPending = new Set(Object.keys(PENDING));

for (const file of files) {
    const src = stripComments(readFileSync(file, 'utf8'));
    const hits = rulesFor(file)
        .map((r) => ({ r, n: (src.match(r.re) ?? []).length }))
        .filter((h) => h.n > 0);

    if (hits.length === 0) {
        stillPending.delete(file);
        continue;
    }

    if (PENDING[file]) continue;

    failures++;
    console.error(`${file}`);
    for (const { r, n } of hits) console.error(`  ${n} <${r.id}> — use ${r.use}`);
}

const cleared = Object.keys(PENDING).filter((f) => !stillPending.has(f));
if (cleared.length) {
    console.error(`\n${cleared.length} file(s) listed as PENDING are already clean — remove them from the list:`);
    cleared.forEach((f) => console.error(`  ${f}`));
    failures += cleared.length;
}

if (Object.keys(PENDING).length > MAX_PENDING) {
    console.error(
        `\nPENDING has ${Object.keys(PENDING).length} entries but MAX_PENDING is ${MAX_PENDING}. ` +
            'The list only shrinks — rebuild the screen instead of listing it.',
    );
    failures++;
}

const remaining = [...stillPending];
if (failures === 0) {
    console.log(
        remaining.length === 0
            ? `components: clean — no screen hand-rolls a control (${files.length} Vue and Blade files).`
            : `components: clean for migrated screens across ${files.length} Vue and Blade files. ` +
                  `${remaining.length} still queued:\n` +
                  remaining.map((f) => `  ${f.replace(/^resources\/(js|views)\//, '')} — ${PENDING[f]}`).join('\n'),
    );
    process.exit(0);
}

console.error(`\ncomponents: ${failures} file(s) hand-rolling controls outside the library.`);
process.exit(1);
