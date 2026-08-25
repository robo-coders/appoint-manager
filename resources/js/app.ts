import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = () => document.documentElement.dataset.appName ?? '';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>([
                './Pages/**/*.vue',
                '!./Pages/Public/**/*.vue',
            ]),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        // Read from the stylesheet so the bar can never drift from the accent.
        color: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#D9A441', // design-tokens-ignore: last-resort literal if the var is unresolved
        delay: 250,
    },
});
