#!/usr/bin/env node
/**
 * Computes WCAG contrast from the actual values in tokens.css, so the numbers
 * in DESIGN.md cannot drift from the palette.
 *
 * The system is light-only, so there is one mode to check — but every text
 * colour is checked against every surface it can land on, because muted text
 * on a white input is the case that actually fails.
 *
 * Run: npm run check:contrast
 */
import { readFileSync } from 'node:fs';

/*
 * The tokens file, overridable by argument.
 *
 * The override exists so the gate itself can be tested: a suite can hand it a
 * palette with a seventh preset that does not clear 4.5:1 and assert that this
 * script fails, without editing the real stylesheet to do it. A checker nobody
 * has ever seen fail is a checker nobody knows works.
 */
const TOKENS_PATH = process.argv[2] ?? 'resources/css/tokens.css';

const css = readFileSync(TOKENS_PATH, 'utf8');

const raw = (name) => (css.match(new RegExp(`--${name}:\\s*([^;]+);`)) || [, ''])[1].trim();

const resolve = (name, depth = 0) => {
    const v = raw(name);
    if (depth > 4 || !v.startsWith('var(')) return v;
    return resolve(v.slice(6, -1).trim(), depth + 1);
};

const toRgb = (v) => {
    if (v.startsWith('#')) {
        const h = v.slice(1);
        const n = h.length === 3 ? h.split('').map((c) => c + c) : h.match(/../g);
        return n.slice(0, 3).map((x) => parseInt(x, 16));
    }
    const m = v.match(/rgb\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/);
    return m ? [+m[1], +m[2], +m[3]] : null;
};

const lum = ([r, g, b]) => {
    const f = (c) => {
        c /= 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};

const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
};

const SURFACES = ['paper', 'paper-sunk', 'white'];

// [foreground, minimum, note]. ink-3 and ink-4 are deliberately not text at
// body size — they are captions at 15px+, disabled states and hairline work.
const TEXT = [
    ['ink', 4.5, 'primary text'],
    ['ink-2', 4.5, 'secondary text'],
    ['danger', 4.5, 'errors'],
    ['accent', 4.5, 'the one accent per screen'],
];

// Never used for text. WCAG exempts inactive controls from contrast, so these
// are held to "perceptible" rather than "readable".
const NON_TEXT = [
    ['ink-3', 2.4, 'disabled controls only'],
    ['ink-4', 1.4, 'struck-through slots, disabled fills, rules'],
];

// The six tenant brand presets, read from tokens.css rather than restated here.
// They used to be a copy in this file, which meant the values that shipped were
// never the values that were checked. A hex field would let someone ship neon
// on white, so the presets must clear 4.5:1 against white button text and
// remain legible on paper.
const BRAND_PRESETS = Object.fromEntries(
    [...css.matchAll(/--brand-(?!fg\b)([a-z]+):\s*([^;]+);/g)].map(([, name, value]) => [name, value.trim()]),
);

/*
 * The six the product offers. Named so a preset that gets renamed or deleted in
 * tokens.css fails here rather than quietly shrinking the palette.
 *
 * This is a floor, not an exact count. It used to be `length !== 6`, which
 * meant a SEVENTH preset failed this check for being a seventh preset — before
 * a single contrast ratio was computed. That is the wrong failure: it says
 * "there are too many colours" when the thing worth knowing is whether the new
 * colour is legible. Every preset found below is measured, so adding one that
 * cannot carry white text fails for that reason, in those words.
 */
const REQUIRED_PRESETS = ['forest', 'plum', 'navy', 'ochre', 'slate', 'clay'];

const missing = REQUIRED_PRESETS.filter((name) => !(name in BRAND_PRESETS));

if (missing.length) {
    console.error(`contrast: tokens.css is missing brand preset(s): ${missing.join(', ')}.`);
    process.exit(1);
}

let failed = 0;
const check = (label, fg, bg, min) => {
    const r = ratio(fg, bg);
    const ok = r >= min;
    if (!ok) failed++;
    console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label.padEnd(40)} ${r.toFixed(2).padStart(6)}:1  (min ${min})`);
};

console.log('text on every surface it lands on');
for (const [name, min] of TEXT) {
    for (const s of SURFACES) check(`${name} on ${s}`, toRgb(resolve(name)), toRgb(resolve(s)), min);
}

console.log('\nnon-text, held to 3:1 or noted');
for (const [name, min, note] of NON_TEXT) {
    check(`${name} on paper — ${note}`, toRgb(resolve(name)), toRgb(resolve('paper')), min);
}

console.log('\nink as a fill');
check('white on ink (primary button)', toRgb(resolve('white')), toRgb(resolve('ink')), 4.5);

console.log('\nthe brand default — dead until --brand existed, so never measured');
check('brand-fg on brand (default)', toRgb(resolve('brand-fg')), toRgb(resolve('brand')), 4.5);

console.log('\ntenant brand presets — white text on the fill, and the fill on paper');
for (const [name, hex] of Object.entries(BRAND_PRESETS)) {
    check(`brand-fg on ${name}`, toRgb(resolve('brand-fg')), toRgb(hex), 4.5);
    check(`${name} on paper`, toRgb(hex), toRgb(resolve('paper')), 3.0);
}

console.log(failed === 0 ? '\ncontrast: all pass' : `\ncontrast: ${failed} FAILING`);
process.exit(failed === 0 ? 0 : 1);
