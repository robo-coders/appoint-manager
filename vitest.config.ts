import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Component tests.
 *
 * The gap this closes was named in the phase 7 report: *"Nothing tests the Vue.
 * `vue-tsc` catches types; it caught neither of the two real rendering bugs I
 * found."* Both of those — a gutter label clipped by half a line, and a freed
 * row squeezed into a four-line column — were found by taking a screenshot and
 * looking at it. Neither is a type error and neither would ever be one.
 *
 * Deliberately separate from `vite.config.js` rather than merged into it. That
 * file exists to serve assets to Laravel: it loads `.env`, derives a dev-server
 * origin, and configures a CORS allowlist, none of which a test run should be
 * doing. Sharing it would mean a test suite that fails differently depending on
 * what is in `.env`.
 */
export default defineConfig({
    plugins: [vue()],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./tests/js/setup.ts'],
        include: ['tests/js/**/*.test.ts'],
        // The default reporter prints a line per file; `verbose` prints a line
        // per test, which is what makes a failure readable in a terminal that
        // has just run four other gates.
        reporters: ['verbose'],
        restoreMocks: true,
    },
});
