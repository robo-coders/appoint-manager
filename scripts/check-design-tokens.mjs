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

const FILES = globSync('resources/{js,views}/**/*.{vue,ts,blade.php}');

const PALETTE =
    'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

const RULES = [
    {
        id: 'default-palette',
        why: 'Tailwind default palette. Use paper/paper-sunk/white, ink/ink-2/ink-3/ink-4, rule/rule-strong, danger, accent.',
        re: new RegExp(`\\b(?:bg|text|border|ring|divide|decoration|fill|stroke|from|via|to|placeholder|caret|accent|outline)-(?:${PALETTE})-\\d{2,3}\\b`, 'g'),
    },
    {
        // `white` is a real surface token in this system (--white, for inputs and
        // unselected slots). `black` is not: ink is the darkest value we have.
        id: 'black',
        why: 'There is no black. --ink (#181714) is the darkest value in the palette.',
        re: /\b(?:bg|text|border|ring|divide|fill|stroke|placeholder)-black\b/g,
    },
    {
        id: 'retired-type-scale',
        why: 'These sizes were removed. The scale is 12/13/14/15/17/20/24/34.',
        re: /\btext-(?:11|16|18|22|26|28|36|48)\b/g,
    },
    {
        id: 'default-type-scale',
        why: 'Off the 12/13/14/15/17/20/24/34 scale.',
        re: /\btext-(?:xs|sm|base|lg|xl|[2-9]xl)\b/g,
    },
    {
        id: 'font-weight',
        why: 'Only font-normal (400) and font-medium (500) exist.',
        re: /\bfont-(?:thin|extralight|light|semibold|bold|extrabold|black)\b/g,
    },
    {
        id: 'shadow',
        why: 'The focus ring is the only shadow in the product.',
        re: /\bshadow-(?:sm|md|lg|xl|2xl|inner)\b|\bshadow\b(?!-(?:ring|none))/g,
    },
    {
        id: 'radius',
        why: '6px on everything: rounded (or rounded-none for table rows). No pills, no large cards.',
        re: /\brounded-(?:lg|xl|2xl|3xl|full|sm|md)\b/g,
    },
    {
        id: 'raw-hex',
        why: 'Raw colour. Every colour comes from resources/css/tokens.css.',
        re: /#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b(?![0-9a-zA-Z])/g,
    },
    {
        id: 'off-scale-space',
        why: 'Space scale is 4/8/12/16/24/32/48/64 (1,2,3,4,6,8,12,16).',
        re: /\b(?:p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml|gap|gap-x|gap-y|space-x|space-y)-(?:5|7|9|10|11|13|14|15|18|20|24|28|32|36|40|44|48|52|56|60|64|72|80|96)\b/g,
    },
    {
        id: 'uppercase-label',
        why: 'Sentence case everywhere. No ALL CAPS labels.',
        re: /\buppercase\b/g,
    },
    {
        id: 'gradient-blur',
        why: 'Gradients and blur are forbidden.',
        re: /\b(?:bg-gradient-to-[a-z]+|backdrop-blur(?:-\w+)?|\bblur-\w+)\b/g,
    },
];

let total = 0;
let ignoredTotal = 0;
const byFile = new Map();

for (const file of FILES) {
    const raw = readFileSync(file, 'utf8');

    // A line carrying `design-tokens-ignore` opts out. Every use must say why
    // in the same comment, and they are counted in the summary below.
    const lines = raw.split('\n');
    const ignored = lines.filter((l) => l.includes('design-tokens-ignore')).length;
    ignoredTotal += ignored;
    const src = lines.filter((l) => !l.includes('design-tokens-ignore')).join('\n');

    for (const rule of RULES) {
        rule.re.lastIndex = 0;
        const hits = src.match(rule.re);
        if (!hits) continue;
        total += hits.length;
        if (!byFile.has(file)) byFile.set(file, []);
        byFile.get(file).push({ rule, hits: [...new Set(hits)], count: hits.length });
    }
}

if (total === 0) {
    console.log(
        `design tokens: clean — no off-token values in resources/` +
            (ignoredTotal ? ` (${ignoredTotal} explicit opt-out${ignoredTotal === 1 ? '' : 's'})` : '') +
            '.',
    );
    process.exit(0);
}

console.error(`design tokens: ${total} off-token value(s) in ${byFile.size} file(s)\n`);
for (const [file, groups] of [...byFile.entries()].sort()) {
    console.error(file);
    for (const g of groups) {
        console.error(`  ${String(g.count).padStart(3)}  ${g.rule.id}: ${g.hits.slice(0, 6).join(', ')}${g.hits.length > 6 ? ', …' : ''}`);
        console.error(`       ${g.rule.why}`);
    }
}
process.exit(1);
