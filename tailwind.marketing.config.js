import base from './tailwind.config.js';

/**
 * The marketing site ships its own stylesheet, for the same reason public
 * booking does: a stranger reading the front page should not download the
 * operator app's CSS, and the operator app should not download the front
 * page's. Same theme, different content list.
 *
 * The content list is narrow on purpose. Marketing is Blade and nothing else —
 * no Vue, deliberately, so that a vertical's copy never lands in the admin SPA
 * (REBUILD.md, phase 11) — so `resources/js` is not scanned at all here.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    ...base,
    content: ['./resources/views/marketing/**/*.blade.php'],
};
