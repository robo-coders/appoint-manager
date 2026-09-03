import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

/**
 * The dev server runs on its own origin, separate from `php artisan serve`.
 * That split is the whole reason `App\Http\Middleware\SecurityHeaders` has a
 * Vite carve-out: with `npm run dev` running, every script and stylesheet the
 * page asks for comes from here, not from the app's origin, and a CSP of
 * `'self'` blocks the lot. See README, "The dev-server carve-out in the CSP".
 *
 * The host and port are named exactly once, here, and read from the
 * environment so `.env` is the single place they change. `origin` is derived
 * rather than restated — a port that is correct in `port` and stale in
 * `origin` produces asset URLs that point at nothing, which looks identical to
 * the CSP failure and is not. Nothing on the PHP side hardcodes either value:
 * the middleware reads the origin back out of the hot file Vite writes here.
 */
export default defineConfig(({ mode }) => {
    // Third argument '' loads every key, not just the VITE_-prefixed ones —
    // APP_URL is needed below and is deliberately not exposed to the client.
    const env = loadEnv(mode, process.cwd(), '');

    const host = env.VITE_DEV_HOST || 'localhost';
    const port = Number(env.VITE_DEV_PORT || 5173);
    const origin = `http://${host}:${port}`;

    /*
     * The page itself is served from APP_URL, so its requests to this server
     * are cross-origin and need naming here. Both spellings of the loopback
     * address are allowed because APP_URL pins one and people type the other.
     */
    const appUrl = env.APP_URL || 'http://127.0.0.1:8000';
    const appPort = new URL(appUrl).port || '8000';
    const allowedOrigins = [...new Set([appUrl, `http://localhost:${appPort}`, `http://127.0.0.1:${appPort}`])];

    return {
        server: {
            host,
            port,
            // Fail loudly on a taken port rather than silently moving to
            // another one: a moved port means the hot file, the asset URLs and
            // the CSP all still agree, but with a server that is not there.
            strictPort: true,
            origin,
            cors: { origin: allowedOrigins },
        },

        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    /*
                     * The marketing site is Blade with no JavaScript entry of
                     * its own, so its stylesheet is named here rather than
                     * imported from a `.ts` file the way `booking.css` is.
                     *
                     * One stylesheet, for the whole surface. There were two —
                     * `marketing.css` for the ledger pages and this one for the
                     * editorial pages — which meant two type scales, two
                     * footers and two token files on one domain. The ledger
                     * half is deleted, not deprecated.
                     */
                    'resources/css/marketing-editorial.css',
                    'resources/js/app.ts',
                    'resources/js/booking.ts',
                    'resources/js/manage.ts',
                    'resources/js/offer.ts',
                ],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
