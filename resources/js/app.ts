import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { toast } from './lib/toast';
import { pinZiggyToCurrentHost, sameHostRoute } from './lib/ziggyHost';

pinZiggyToCurrentHost();
window.route = sameHostRoute;

const appName = () => document.documentElement.dataset.appName ?? '';

const flashToast = (props: Record<string, unknown> | undefined) => {
    const message = props?.toast;

    if (typeof message === 'string' && message !== '') toast.success(message);
};

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
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue);

        // ZiggyVue defaults `absolute` to true. Overwrite so every `route()`
        // in script or template stays on this host. See lib/ziggyHost.ts.
        app.config.globalProperties.route = sameHostRoute;
        app.provide('route', sameHostRoute);

        flashToast(props.initialPage?.props);
        router.on('success', (event) => flashToast(event.detail.page.props));

        app.mount(el);
    },
    progress: {
        // The bar is injected into the document, so it can resolve the variable
        // itself. The literal fallback that used to sit here was an amber from
        // the retired dark system — a colour that is no longer in the product.
        color: 'var(--accent)',
        delay: 250,
    },
});
