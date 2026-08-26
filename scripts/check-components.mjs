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

// Screens not yet rebuilt, with the phase that will remove them from this list.
const PENDING = {
    /*
     * Blade. The marketing site is not built on the component library at all —
     * it restates the button in `cta.blade.php` and styles its links inline.
     * Phase 11 rebuilds marketing; until then these are listed, not rewritten,
     * and the rule that catches them is not weakened to let them through.
     *
     * `cta-quiet.blade.php` and `layout.blade.php` are NOT listed: their links
     * are links, not controls wearing a link's tag, and they pass as they are.
     */
    'resources/views/marketing/partials/cta.blade.php': 'phase 11 — marketing',

    'resources/js/Pages/SuperAdmin/Index.vue': 'phase 9 — super admin',
    'resources/js/Pages/Auth/Login.vue': 'phase 8 — auth',
};

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
 */
const MAX_PENDING = 3;

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
 * Vue and Blade. `resources/views/**` covers marketing, the public shells and
 * the mail templates; the last of those is in scope deliberately rather than
 * exempted, so a table-based email is a decision somebody makes on purpose.
 */
const files = [...globSync('resources/js/**/*.vue'), ...globSync('resources/views/**/*.blade.php')]
    .filter((f) => !ALLOWED_DIRS.some((d) => f.startsWith(d)))
    .sort();

let failures = 0;
const stillPending = new Set(Object.keys(PENDING));

for (const file of files) {
    const src = readFileSync(file, 'utf8');
    const hits = RULES.map((r) => ({ r, n: (src.match(r.re) ?? []).length })).filter((h) => h.n > 0);

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
