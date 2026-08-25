#!/usr/bin/env node
/**
 * Fails if a screen hand-rolls a control the component library already owns.
 *
 * Screens still queued for a later phase are listed in PENDING with the phase
 * that will clear them. That list only ever shrinks — adding to it is how this
 * check stops meaning anything.
 *
 * Run: npm run check:components
 */
import { readFileSync, globSync } from 'node:fs';

// Screens not yet rebuilt, with the phase that will remove them from this list.
const PENDING = {
    'resources/js/Pages/Public/BookingIsland.vue': 'phase 4 — public booking',
    'resources/js/Pages/Public/ManageIsland.vue': 'phase 4 — public booking',
    'resources/js/Pages/Public/OfferIsland.vue': 'phase 4 — public booking',
    'resources/js/Pages/Diary/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Bookings/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Customers/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Services/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Staff/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Availability/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/TimeOff/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Waitlist/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Settings/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Billing/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Imports/Index.vue': 'phase 5 — operator app',
    'resources/js/Pages/Onboarding/Index.vue': 'phase 6 — onboarding',
    'resources/js/Pages/SuperAdmin/Index.vue': 'phase 7 — super admin',
    'resources/js/Pages/Auth/Login.vue': 'phase 6 — auth',
    'resources/js/Layouts/AppLayout.vue': 'phase 5 — operator app',
    'resources/js/Components/CommandPalette.vue': 'phase 10 — command palette',
    'resources/js/Components/WeeklyHoursGrid.vue': 'phase 5 — operator app',
};

// The library itself is where these elements are allowed to exist.
const ALLOWED_DIRS = ['resources/js/Components/ui/'];

const RULES = [
    { id: 'input', re: /<input\b/g, use: 'ui/TextInput, ui/Checkbox, ui/DatePicker, ui/TimePicker' },
    { id: 'textarea', re: /<textarea\b/g, use: 'ui/Textarea' },
    { id: 'select', re: /<select\b/g, use: 'ui/Select or ui/Combobox' },
    { id: 'button', re: /<button\b/g, use: 'ui/Button, ui/Menu or ui/MenuItem' },
    { id: 'table', re: /<table\b/g, use: 'ui/Table' },
    { id: 'modal', re: /role="(dialog|alertdialog)"/g, use: 'ui/Modal, ui/SlideOver or ui/ConfirmDialog' },
];

const files = globSync('resources/js/**/*.vue').filter(
    (f) => !ALLOWED_DIRS.some((d) => f.startsWith(d)) && !f.startsWith('resources/js/Pages/Dev/'),
);

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

const remaining = [...stillPending];
if (failures === 0) {
    console.log(
        remaining.length === 0
            ? 'components: clean — no screen hand-rolls a control.'
            : `components: clean for migrated screens. ${remaining.length} still queued:\n` +
                  remaining.map((f) => `  ${f.replace('resources/js/', '')} — ${PENDING[f]}`).join('\n'),
    );
    process.exit(0);
}

console.error(`\ncomponents: ${failures} file(s) hand-rolling controls outside the library.`);
process.exit(1);
