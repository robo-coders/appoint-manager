#!/usr/bin/env node
/**
 * Fails if the old product name survives anywhere in the tree.
 *
 * The rename from Kestrel to Appoint Manager touched a lot of surfaces — cookie
 * names, queue prefixes, window globals, asset paths, prose — and the failure
 * mode is not a compile error. It is a stale string that keeps working until
 * the day it does not: a session cookie nobody clears, a Horizon prefix that
 * orphans every queued job the moment it changes, a support email nobody reads.
 * The only reliable way to know the rename finished is to assert that the word
 * is gone.
 *
 * Run: npm run check:name
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, extname } from 'node:path';

const ROOT = process.cwd();
const NEEDLE = /kestrel/i;

/*
 * Third-party code and generated lockfiles are out of scope: we do not own
 * their contents, and npm names the lockfile's root package after the working
 * directory, which is still ~/Projects/Kestrel. Renaming the directory is a
 * separate job from renaming the product; this check is about the product.
 */
const SKIP_DIRS = new Set(['vendor', 'node_modules', '.git']);

const SKIP_FILES = new Set(['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb']);

/*
 * Generated output, by relative path prefix.
 *
 * These are skipped for a reason worth writing down. Compiled Blade views under
 * storage/framework/views embed the ABSOLUTE path of the file they came from,
 * and this repository lives at ~/Projects/Kestrel — so every one of them
 * contains the word, and not one of them is about the product name. Scanning
 * them finds 80-odd hits that no edit to this codebase can ever clear.
 *
 * The working directory is a real outstanding item, recorded in DECISIONS.md,
 * and it is a different job from renaming the product. A check that cannot go
 * green until somebody moves a folder on one particular laptop is a check that
 * gets switched off.
 */
const SKIP_PATHS = [
    'storage/framework/',
    'storage/logs/',
    'public/build/',
    '.phpunit.cache/',
    'bootstrap/cache/',
];

/*
 * The one exemption, and it is deliberate.
 *
 * DECISIONS.md is the record of the rename itself. It names the old database,
 * the old cookie, the old queue prefix and the old window globals, because a
 * decision record that cannot name what was renamed is not a record of
 * anything. Every other file — code, config, prose, docs — must be clean.
 *
 * If you are tempted to add a second entry here, the answer is almost always
 * to fix the file instead.
 */
const ALLOWED = new Set([
    'DECISIONS.md',
    // A check has to be allowed to name the thing it is checking for.
    'scripts/check-name.mjs',
]);

// Binary and build output. Reading these produces noise, not findings.
const SKIP_EXT = new Set([
    '.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.icns',
    '.woff', '.woff2', '.ttf', '.otf', '.eot',
    '.pdf', '.zip', '.gz', '.tar', '.mp4', '.webm', '.mp3', '.sqlite', '.db',
]);

const MAX_BYTES = 2 * 1024 * 1024;

const findings = [];
let scanned = 0;

const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);

        if (entry.isDirectory()) {
            if (SKIP_DIRS.has(entry.name)) continue;
            walk(full);
            continue;
        }

        if (!entry.isFile()) continue;
        if (SKIP_FILES.has(entry.name)) continue;
        if (SKIP_EXT.has(extname(entry.name).toLowerCase())) continue;

        const rel = relative(ROOT, full);
        if (ALLOWED.has(rel)) continue;
        if (SKIP_PATHS.some((prefix) => rel.startsWith(prefix))) continue;
        if (statSync(full).size > MAX_BYTES) continue;

        let src;
        try {
            src = readFileSync(full, 'utf8');
        } catch {
            continue; // Unreadable or not text. Nothing to assert about it.
        }

        scanned++;

        src.split('\n').forEach((line, i) => {
            if (NEEDLE.test(line)) findings.push({ file: rel, line: i + 1, text: line.trim().slice(0, 120) });
        });
    }
};

walk(ROOT);

if (findings.length === 0) {
    console.log(
        `name: clean — "kestrel" appears in none of ${scanned} files ` +
            `(${[...ALLOWED].join(', ')} exempt as the rename's own record).`,
    );
    process.exit(0);
}

console.error(`name: the old product name survives in ${findings.length} place(s)\n`);
for (const { file, line, text } of findings) console.error(`  ${file}:${line}  ${text}`);
console.error('\nThe product is Appoint Manager. Rename it, or say why it cannot be renamed.');
process.exit(1);
