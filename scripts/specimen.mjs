#!/usr/bin/env node
/** Renders a specimen sheet straight from tokens.css so it cannot drift. */
import { readFileSync, writeFileSync } from 'node:fs';
const css = readFileSync('resources/css/tokens.css', 'utf8');
const dark = css; // light-only system; kept as the single source block
const light = css;
const g = (b, n) => (b.match(new RegExp(`--${n}:\\s*([^;]+);`)) || [, ''])[1].trim();
const v = (b, n) => { let x = g(b, n) || g(dark, n); let i = 0; while (x.startsWith('var(') && i++ < 4) x = g(b, x.slice(6, -1)) || g(dark, x.slice(6, -1)); return x; };

const SURF = ['paper', 'paper-sunk', 'white'];
const TEXT = ['ink', 'ink-2', 'ink-3', 'ink-4'];
const SEM = ['ink', 'accent', 'danger'];
const SIZES = [34, 24, 20, 17, 15, 14, 13, 12];

const panel = (block, x, title) => {
    const bg = v(block, 'paper');
    const ink = v(block, 'ink'), mut = v(block, 'ink-2');
    let o = `<rect x="${x}" y="0" width="470" height="620" fill="${bg}"/>`;
    o += `<text x="${x + 24}" y="40" fill="${mut}" font-family="sans-serif" font-size="13">${title}</text>`;
    let y = 64;
    o += `<text x="${x + 24}" y="${y}" fill="${mut}" font-family="sans-serif" font-size="13">Surfaces</text>`;
    y += 12;
    SURF.forEach((n, i) => { o += `<rect x="${x + 24 + i * 140}" y="${y}" width="130" height="44" fill="${v(block, n)}" stroke="#DDD9D2"/>`; });
    y += 60;
    o += `<text x="${x + 24}" y="${y}" fill="${mut}" font-family="sans-serif" font-size="13">Text</text>`;
    y += 20;
    TEXT.forEach((n) => { o += `<text x="${x + 24}" y="${y}" fill="${v(block, n)}" font-family="sans-serif" font-size="15">${n} — the quick brown fox 0123456789</text>`; y += 24; });
    y += 12;
    o += `<text x="${x + 24}" y="${y}" fill="${mut}" font-family="sans-serif" font-size="13">Ink, accent, danger</text>`;
    y += 12;
    SEM.forEach((n, i) => { o += `<rect x="${x + 24 + i * 140}" y="${y}" width="130" height="34" fill="${v(block, n)}"/>`; });
    y += 52;
    o += `<rect x="${x + 24}" y="${y}" width="180" height="36" fill="${v(block, 'ink')}" rx="6"/>`;
    o += `<text x="${x + 114}" y="${y + 23}" fill="${v(block, 'white')}" font-family="sans-serif" font-size="14" text-anchor="middle" font-weight="500">Confirm booking</text>`;
    y += 62;
    o += `<text x="${x + 24}" y="${y}" fill="${mut}" font-family="sans-serif" font-size="13">Scale</text>`;
    y += 14;
    o += `<rect x="${x + 24}" y="${y}" width="422" height="1" fill="${'#E8E4DD'}"/>`;
    y += 8;
    SIZES.forEach((s) => {
        y += Math.min(s, 34) + 8;
        o += `<text x="${x + 24}" y="${y}" fill="${ink}" font-family="sans-serif" font-size="${Math.min(s, 34)}" font-weight="500" letter-spacing="${s >= 34 ? -1 : s >= 20 ? -0.5 : -0.15}">Appoint Manager ${s}</text>`;
        o += `<text x="${x + 446}" y="${y}" fill="${mut}" font-family="monospace" font-size="11" text-anchor="end">09:45  £42.50</text>`;
    });
    return o;
};
const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 470 620">${panel(dark, 0, 'Appoint Manager — light, warm paper, ink actions')}</svg>`;
writeFileSync(process.argv[2] || 'specimen.svg', svg);
console.log('specimen written');
