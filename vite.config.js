import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Referenced directly by the Blade pages that are not part of the
                // Inertia SPA: the marketing site and the offer-taken page. Without
                // it here those templates 500 on a manifest lookup.
                'resources/css/app.css',
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
});
