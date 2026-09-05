#!/usr/bin/env node
/**
 * Fails if any template reaches past the design tokens.
 *
 * The Tailwind theme replaces (rather than extends) colour, type, radius and
 * shadow, so these classes silently compile to nothing instead of erroring.
 * This check is what turns that silence into a failure.
 *
 * Run: npm run check:design
 */
import { readFileSync } from 'node:fs';
import { globSync } from 'node:fs';

import tailwind from '../tailwind.config.js';

/*
 * Everything authored under resources/, not just the Vue and Blade trees.
 * The previous glob stopped at `resources/{js,views}`, which left the
 * stylesheets and the SVG marks — the two places a raw hex is most likely to
 * be typed — completely unguarded.
 *
 * tokens.css is the one exemption: it is where the raw values are *supposed*
 * to live, and it is the file every other value resolves back to.
 */
const TOKENS = 'resources/css/tokens.css';
const EDITORIAL_TOKENS = 'resources/css/marketing-editorial-tokens.css';

/*
 * The logo files, and the third exemption from the raw-colour rule.
 *
 * They are artwork, not markup. An SVG loaded through `<img src>` — which is
 * how every one of them is used — is its own document: it cannot see the page's
 * stylesheet, so it cannot read `var(--ink)`, and a fill written that way
 * renders as nothing at all. The colours have to be literals in the file.
 *
 * So they are exempt from `raw-hex` and gated by BRAND_ARTWORK below instead,
 * which is the stricter rule of the two: it does not merely allow a literal, it
 * requires every literal in these files to be one of the three token values the
 * brand is drawn from. A logo recoloured to something off-palette fails here.
 *
 * `icon-on-paper.svg` is in the list and used by nothing. It is the source the
 * favicons under `public/` were rendered from, kept beside the four the UI
 * loads so the next render starts from the same file.
 */
const BRAND_ARTWORK = globSync('resources/js/assets/*.svg');

const FILES = globSync('resources/**/*.{vue,ts,js,mjs,css,blade.php,svg}').filter(
    (f) => f !== TOKENS && f !== EDITORIAL_TOKENS && !BRAND_ARTWORK.includes(f),
);

const PALETTE =
    'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

/*
 * `scope` decides which files a rule is asked about. The class rules describe
 * Tailwind class names, so running them over a stylesheet only produces false
 * positives — `box-shadow: var(--focus-ring)` is the *correct* way to write the
 * one shadow this system has, and the word "uppercase" appears in prose. The
 * stylesheets get their own rules, written against CSS rather than classes.
 *
 *   markup — .vue, .ts, .js, .blade.php
 *   style  — .css
 *   all    — every scanned file, .svg included
 */
const RULES = [
    {
        scope: 'markup',
        id: 'default-palette',
        why: 'Tailwind default palette. Use paper/paper-sunk/white, ink/ink-2/ink-3/ink-4, rule/rule-strong, danger, accent.',
        re: new RegExp(`\\b(?:bg|text|border|ring|divide|decoration|fill|stroke|from|via|to|placeholder|caret|accent|outline)-(?:${PALETTE})-\\d{2,3}\\b`, 'g'),
    },
    {
        // `white` is a real surface token in this system (--white, for inputs and
        // unselected slots). `black` is not: ink is the darkest value we have.
        scope: 'markup',
        id: 'black',
        why: 'There is no black. --ink (#181714) is the darkest value in the palette.',
        re: /\b(?:bg|text|border|ring|divide|fill|stroke|placeholder)-black\b/g,
    },
    {
        scope: 'markup',
        id: 'retired-type-scale',
        why: 'These sizes were removed. The scale is 12/13/14/15/17/20/24/34.',
        re: /\btext-(?:11|16|18|22|26|28|36|48)\b/g,
    },
    {
        scope: 'markup',
        id: 'default-type-scale',
        why: 'Off the 12/13/14/15/17/20/24/34 scale.',
        re: /\btext-(?:xs|sm|base|lg|xl|[2-9]xl)\b/g,
    },
    {
        scope: 'markup',
        id: 'font-weight',
        why: 'Only font-normal (400) and font-medium (500) exist.',
        re: /\bfont-(?:thin|extralight|light|semibold|bold|extrabold|black)\b/g,
    },
    {
        scope: 'markup',
        id: 'shadow',
        why: 'The focus ring is the only shadow in the product.',
        re: /\bshadow-(?:sm|md|lg|xl|2xl|inner)\b|\bshadow\b(?!-(?:ring|none))/g,
    },
    {
        scope: 'markup',
        id: 'radius',
        why: '6px on everything: rounded (or rounded-none for table rows). No pills, no large cards.',
        re: /\brounded-(?:lg|xl|2xl|3xl|full|sm|md)\b/g,
    },
    {
        scope: 'all',
        id: 'raw-hex',
        why: 'Raw colour. Every colour comes from resources/css/tokens.css.',
        re: /#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b(?![0-9a-zA-Z])/g,
    },
    {
        scope: 'style',
        id: 'css-raw-colour',
        why: 'Raw colour in a stylesheet. Reference the token: var(--ink), var(--rule).',
        re: /:\s*(?:rgba?|hsla?|color-mix|oklch|lab)\(/g,
    },
    {
        scope: 'style',
        id: 'css-shadow',
        why: 'The focus ring is the only shadow in the product: box-shadow: var(--focus-ring).',
        re: /box-shadow:(?![ \t]*(?:var\(--focus-ring\)|none|inherit|unset))/g,
    },
    {
        scope: 'style',
        id: 'css-uppercase',
        why: 'Sentence case everywhere. No ALL CAPS labels.',
        re: /text-transform:\s*uppercase/g,
    },
    {
        scope: 'markup',
        id: 'off-scale-space',
        why: 'Space scale is 4/8/12/16/24/32/48/64 (1,2,3,4,6,8,12,16).',
        re: /\b(?:p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml|gap|gap-x|gap-y|space-x|space-y)-(?:5|7|9|10|11|13|14|15|18|20|24|28|32|36|40|44|48|52|56|60|64|72|80|96)\b/g,
    },
    {
        scope: 'markup',
        id: 'uppercase-label',
        why: 'Sentence case everywhere. No ALL CAPS labels.',
        re: /\buppercase\b/g,
    },
    {
        scope: 'markup',
        id: 'gradient-blur',
        why: 'Gradients and blur are forbidden.',
        re: /\b(?:bg-gradient-to-[a-z]+|backdrop-blur(?:-\w+)?|\bblur-\w+)\b/g,
    },
];

/*
 * Values that must mirror a token but cannot hold a CSS variable: an HTML meta
 * attribute and a web app manifest are both read before any stylesheet is.
 * They are allowed to restate the value — they are not allowed to drift from
 * it, which is what this catches.
 */
const tokensCss = readFileSync(TOKENS, 'utf8');
const tokenValue = (name) => (tokensCss.match(new RegExp(`--${name}:\\s*([^;]+);`)) || [, ''])[1].trim().toLowerCase();

const MIRRORS = [
    { file: 'resources/views/partials/head.blade.php', re: /<meta name="theme-color" content="(#[0-9a-fA-F]{3,8})"/, token: 'paper' },
    // The manifest is composed in SurfaceRoutes, not a static file, so the
    // name can come from config. The two colours still cannot: a JSON body
    // cannot read a CSS variable.
    { file: 'app/Support/SurfaceRoutes.php', re: /'theme_color'\s*=>\s*'(#[0-9a-fA-F]{3,8})'/, token: 'paper' },
    { file: 'app/Support/SurfaceRoutes.php', re: /'background_color'\s*=>\s*'(#[0-9a-fA-F]{3,8})'/, token: 'paper' },
];

/*
 * The three values the logo files are allowed to contain, by the token each one
 * is. Every hex in every brand asset must be one of them — see BRAND_ARTWORK.
 *
 * This is the same bargain as MIRRORS, one level stricter. A mirror names one
 * value in one file and asserts it has not drifted; this asserts that a whole
 * file contains nothing *but* values that have not drifted, which is what you
 * want from a file nobody reads and everybody looks at.
 */
const ARTWORK_TOKENS = ['ink', 'paper', 'accent'];

const drift = [];

const artworkPalette = new Map(ARTWORK_TOKENS.map((token) => [tokenValue(token), token]));

for (const asset of BRAND_ARTWORK) {
    const hexes = readFileSync(asset, 'utf8').match(/#[0-9a-fA-F]{3,8}\b/g) ?? [];

    for (const hex of [...new Set(hexes)]) {
        if (!artworkPalette.has(hex.toLowerCase())) {
            drift.push(
                `${asset}: ${hex} is not a brand colour ` +
                    `(${ARTWORK_TOKENS.map((t) => `--${t} ${tokenValue(t)}`).join(', ')})`,
            );
        }
    }
}

for (const mirror of MIRRORS) {
    const found = (readFileSync(mirror.file, 'utf8').match(mirror.re) || [, null])[1];
    const expected = tokenValue(mirror.token);

    if (found === null) {
        drift.push(`${mirror.file}: expected a value mirroring --${mirror.token}, found none`);
    } else if (found.toLowerCase() !== expected) {
        drift.push(`${mirror.file}: ${found} does not match --${mirror.token} (${expected})`);
    }
}

/*
 * The mockups in .design/mockups/ carry a copy of the token block, because they
 * are standalone files you open straight from disk with no build step. That
 * copy is the same hazard the brand presets were: a second set of values that
 * nobody notices has gone stale.
 *
 * They are gated rather than generated. Generating the block would mean a
 * mockup could not be opened without running a script first, which is the one
 * property that makes them useful as a reference — and a generated file that is
 * committed drifts the moment somebody edits it by hand anyway. A gate keeps
 * them static, openable, and honest.
 *
 * **This glob had stopped matching anything at all.** It was
 * `public/mockups/*.html`, which was already non-recursive — DECISIONS.md
 * recorded that the marketing explorations in `public/mockups/directions/` were
 * therefore never checked — and then the mockups moved out of `public/`
 * entirely, to `.design/`. From that move until now the gate globbed a
 * directory that does not exist, found zero files, and printed "0 mockup token
 * blocks verified" in the middle of a success message. A gate that reports
 * clean over nothing is worse than no gate, because it is quoted.
 *
 * `**` rather than `*`, so a mockup in a subfolder is checked where the old
 * glob would have skipped it. All five files pass as they stand; nothing in
 * them was edited to make that true.
 */
/*
 * Product mockups (dashboard, bookings-table, …) copy tokens.css. The
 * editorial marketing direction and the archived ledger homepage are a
 * different token system on purpose — they must not be forced onto --paper.
 *
 * `Market-site/` is the third exemption and the one that is not ours at all:
 * it is a Claude Design bundle export, machine-generated markup with its own
 * inlined variables and no `:root` block to compare. It is a reference
 * photograph, not a mockup this repo maintains, so gating it asserts nothing
 * except that an export has the shape of a hand-written file.
 */
const MOCKUPS = globSync('.design/mockups/**/*.html').filter((f) => {
    const skip = f.includes('/directions/')
        || f.includes('/Market-site/')
        || /direction-a-/.test(f)
        || /archived-/.test(f);

    return !skip;
});

// Whitespace and a leading zero are formatting, not value. The mockups are
// written condensed; tokens.css is not.
const normalise = (value) =>
    value
        .toLowerCase()
        .replace(/\s+/g, '')
        .replace(/(^|[^\w.])0\./g, '$1.');

const declarations = (block) =>
    new Map([...block.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map(([, name, value]) => [name, normalise(value)]));

const blockOf = (css, selector) => {
    const at = css.indexOf(selector);
    if (at === -1) return null;
    const open = css.indexOf('{', at);
    const close = css.indexOf('}', open);

    return open === -1 || close === -1 ? null : css.slice(open + 1, close);
};

const SCOPES = [':root', "[data-density='roomy']", "[data-density='console']"];

for (const mockup of MOCKUPS) {
    const html = readFileSync(mockup, 'utf8');

    for (const selector of SCOPES) {
        const expected = declarations(blockOf(tokensCss, selector) ?? '');
        const found = declarations(blockOf(html, selector) ?? '');

        if (found.size === 0) {
            drift.push(`${mockup}: no ${selector} token block found`);
            continue;
        }

        for (const [name, value] of expected) {
            if (!found.has(name)) {
                drift.push(`${mockup} ${selector}: missing ${name} (tokens.css has ${value})`);
            } else if (found.get(name) !== value) {
                drift.push(`${mockup} ${selector}: ${name} is ${found.get(name)}, tokens.css has ${value}`);
            }
        }

        for (const name of found.keys()) {
            if (!expected.has(name)) drift.push(`${mockup} ${selector}: ${name} is not in tokens.css`);
        }
    }
}

/*
 * Chrome utilities that name a token rather than a number — `w-rail`,
 * `h-topbar`, `max-w-measure`. These are the ones that fail *silently*: a
 * misspelt or renamed token produces a class Tailwind has never heard of, it
 * emits nothing, and the element simply has no width. `AppLayout.vue` shipped
 * `w-sidebar`, `w-sidebar-collapsed`, `h-topbar` and `pl-sidebar` against a
 * config that only ever defined `rail` and `rail-collapsed`, so the operator
 * app's nav rail had no width at all and nothing caught it.
 *
 * The valid names come from the Tailwind config itself, plus the handful of
 * framework defaults this codebase actually leans on. Numeric, fractional and
 * arbitrary `[…]` values are Tailwind's own business and are skipped.
 */
const chromeNames = (scaleKey) => {
    const extended = tailwind.theme?.extend?.[scaleKey] ?? {};
    const replaced = tailwind.theme?.[scaleKey] ?? {};
    // Tailwind derives width, height, padding, margin and gap from `spacing`,
    // so a name added there is valid for all of them.
    const spacing = tailwind.theme?.extend?.spacing ?? {};

    return new Set([...Object.keys(extended), ...Object.keys(replaced), ...Object.keys(spacing)]);
};

// Framework defaults kept because the size scales are extended, not replaced.
// Listed rather than inferred so that adding one is a visible decision.
const TAILWIND_DEFAULTS = new Set([
    'full', 'auto', 'screen', 'fit', 'min', 'max', 'px', 'none', 'svh', 'lvh', 'dvh', 'prose',
    'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl',
]);

const CHROME_PREFIXES = [
    ['w', 'width'],
    ['min-w', 'minWidth'],
    ['max-w', 'maxWidth'],
    ['h', 'height'],
    ['min-h', 'minHeight'],
    ['max-h', 'maxHeight'],
    ['pl', 'padding'],
    ['pr', 'padding'],
    ['pt', 'padding'],
    ['pb', 'padding'],
    ['px', 'padding'],
    ['py', 'padding'],
];

const CHROME_RULES = CHROME_PREFIXES.map(([prefix, scaleKey]) => {
    const known = new Set([...chromeNames(scaleKey), ...TAILWIND_DEFAULTS]);

    return {
        scope: 'markup',
        id: `unknown-${prefix}-token`,
        why: `No such ${scaleKey} token in tailwind.config.js, so this class compiles to nothing. Known: ${[...chromeNames(scaleKey)].sort().join(', ') || '(none)'}.`,
        /*
         * A named value only: at least one letter, no digits, no bracket. The
         * leading guard matters — without it `min-h-tap` matches as `h-tap` and
         * the rule reports a bug that is not there.
         */
        re: new RegExp(`(?<![\\w-])(?:[a-z]+:)*${prefix}-([a-z][a-z-]*)\\b`, 'g'),
        keep: (hit) => {
            const name = hit.replace(new RegExp(`^(?:[a-z]+:)*${prefix}-`), '');

            return !known.has(name);
        },
    };
});

RULES.push(...CHROME_RULES);

let total = 0;
let ignoredTotal = 0;
const byFile = new Map();

const scopeOf = (file) => (file.endsWith('.css') ? 'style' : file.endsWith('.svg') ? 'asset' : 'markup');

// Prose is not code. A comment explaining *why* there is one shadow should not
// read as a second shadow.
const stripComments = (src) =>
    src
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');

for (const file of FILES) {
    const raw = readFileSync(file, 'utf8');
    const scope = scopeOf(file);

    // A line carrying `design-tokens-ignore` opts out. Every use must say why
    // in the same comment, and they are counted in the summary below.
    const lines = raw.split('\n');
    const ignored = lines.filter((l) => l.includes('design-tokens-ignore')).length;
    ignoredTotal += ignored;
    const src = stripComments(lines.filter((l) => !l.includes('design-tokens-ignore')).join('\n'));

    for (const rule of RULES) {
        if (rule.scope !== 'all' && rule.scope !== scope) continue;
        rule.re.lastIndex = 0;
        let hits = src.match(rule.re);
        if (rule.keep) hits = (hits ?? []).filter(rule.keep);
        if (!hits || hits.length === 0) continue;
        total += hits.length;
        if (!byFile.has(file)) byFile.set(file, []);
        byFile.get(file).push({ rule, hits: [...new Set(hits)], count: hits.length });
    }
}

if (drift.length) {
    console.error(`design tokens: ${drift.length} value(s) have drifted from tokens.css\n`);
    for (const line of drift) console.error(`  ${line}`);
    console.error('');
}

if (total === 0 && drift.length === 0) {
    console.log(
        `design tokens: clean — ${FILES.length} files under resources/, no off-token values` +
            (ignoredTotal ? `, ${ignoredTotal} explicit opt-out${ignoredTotal === 1 ? '' : 's'}` : '') +
            `, ${MIRRORS.length} mirrored values, ${BRAND_ARTWORK.length} logo files ` +
            `and ${MOCKUPS.length} mockup token blocks verified against tokens.css.`,
    );
    process.exit(0);
}

if (total === 0) process.exit(1);

console.error(`design tokens: ${total} off-token value(s) in ${byFile.size} file(s)\n`);
for (const [file, groups] of [...byFile.entries()].sort()) {
    console.error(file);
    for (const g of groups) {
        console.error(`  ${String(g.count).padStart(3)}  ${g.rule.id}: ${g.hits.slice(0, 6).join(', ')}${g.hits.length > 6 ? ', …' : ''}`);
        console.error(`       ${g.rule.why}`);
    }
}
process.exit(1);
