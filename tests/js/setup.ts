import { config } from '@vue/test-utils';
import { vi } from 'vitest';
import { reactive } from 'vue';

/**
 * The three globals a page component expects to exist, and nothing else.
 *
 * A component test that has to boot Inertia, Ziggy and a Laravel route list is
 * a component test nobody writes. These stubs are the smallest thing that lets
 * a real component render: `route()` returns a path, `usePage()` returns props
 * a test can set, and `<Link>` is an anchor.
 *
 * They are *stubs*, not mocks of behaviour. Nothing here asserts that Inertia
 * was called correctly — that is what the Playwright suite is for. These exist
 * so the markup can be rendered and looked at.
 */

// ---------------------------------------------------------------------------
// Ziggy
// ---------------------------------------------------------------------------

/**
 * `route('diary.index', { date: '2026-08-19' })` -> `/diary?date=2026-08-19`.
 *
 * The real app now generates *relative* URLs so a login opened on 127.0.0.1
 * cannot post to localhost. The stub still returns absolute URLs: that is how
 * `AppLayout.isCurrent()` used to fail (absolute vs path), and pathOf() has
 * to keep handling both.
 */
const routeStub = (name: string, params?: unknown): string => {
    const path = `/${name.replace(/\./g, '/').replace(/\/index$/, '')}`;
    const origin = 'http://localhost';

    if (params === undefined || params === null) return `${origin}${path}`;

    if (typeof params === 'object' && !Array.isArray(params)) {
        const query = new URLSearchParams(
            Object.entries(params as Record<string, unknown>)
                .filter(([, value]) => value !== undefined && value !== null)
                .map(([key, value]) => [key, String(value)]),
        ).toString();

        return query ? `${origin}${path}?${query}` : `${origin}${path}`;
    }

    return `${origin}${path}/${String(params)}`;
};

(globalThis as unknown as { route: typeof routeStub }).route = routeStub;

/*
 * And on the component instance, which is where a *template* looks.
 *
 * `route()` in a `<script>` block resolves to the global above; the same call
 * in a template compiles to `_ctx.route` and finds nothing, so a page with
 * `:href="route('login')"` in it died on mount with "route is not a function"
 * and no test could reach it. `resources/js/app.ts` registers both — a global
 * property and an injection — so this registers both too.
 */
config.global.mocks = { route: routeStub };
config.global.provide = { route: routeStub };

// ---------------------------------------------------------------------------
// Inertia
// ---------------------------------------------------------------------------

/** Page props the test controls. Reset between tests by `setPageProps`. */
export const pageProps: Record<string, unknown> = {};

export const setPageProps = (props: Record<string, unknown>) => {
    for (const key of Object.keys(pageProps)) delete pageProps[key];
    Object.assign(pageProps, props);
};

export const router = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(() => () => {}),
};

/**
 * Every form `useForm` has handed out this test, in creation order.
 *
 * A page's form is internal to it — `<script setup>` exposes nothing — so
 * without this a test can fill in fields and click things but can never say
 * "and now the server rejects it", which is the half of a form that has the
 * bugs in it. `forms[0].setError(...)` is a test standing in for a 422.
 */
export const forms: FormStub[] = [];

type FormStub = Record<string, unknown> & {
    errors: Record<string, string>;
    processing: boolean;
    hasErrors: boolean;
    setError: (field: string, message: string) => void;
    clearErrors: (...fields: string[]) => void;
    post: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
};

/*
 * Reactive, and with `setError`/`clearErrors` that actually do something.
 *
 * The stub used to be a plain object literal, which meant two things silently:
 * a component that set an error re-rendered nothing, and `setError` was not
 * defined at all — so a page doing its own client-side validation could not be
 * tested, and the failure looked like "is not a function" rather than like a
 * missing stub.
 */
const makeForm = (initial: Record<string, unknown>): FormStub => {
    const form = reactive({
        ...initial,
        errors: {} as Record<string, string>,
        processing: false,
        isDirty: false,
        recentlySuccessful: false,
        hasErrors: false,
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        setError: (field: string, message: string) => {
            form.errors[field] = message;
            form.hasErrors = true;
        },
        clearErrors: (...fields: string[]) => {
            const keys = fields.length ? fields : Object.keys(form.errors);
            keys.forEach((key) => delete form.errors[key]);
            form.hasErrors = Object.keys(form.errors).length > 0;
        },
    }) as FormStub;

    forms.push(form);

    return form;
};

export const resetForms = () => forms.splice(0, forms.length);

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps, url: (pageProps.__url as string) ?? '/' }),
    router,
    Head: { name: 'Head', render: () => null },
    Link: {
        name: 'Link',
        props: { href: { type: String, default: '#' }, method: String, as: String },
        template: '<a :href="href"><slot /></a>',
    },
    useForm: (initial: Record<string, unknown>) => makeForm(initial),
}));

// ---------------------------------------------------------------------------
// jsdom gaps
// ---------------------------------------------------------------------------

/*
 * jsdom implements neither. `NavRail` reads `matchMedia` through `AppLayout`
 * and `Combobox` uses `ResizeObserver`; without these a component that is
 * perfectly correct throws on mount and the failure says nothing about the
 * markup.
 */
if (!window.matchMedia) {
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia;
}

if (!globalThis.ResizeObserver) {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
}

/*
 * `Teleport to="body"` renders nothing findable in a wrapper by default. Modal,
 * SlideOver and ConfirmDialog all use it, so stubbing it keeps their content
 * inside the wrapper where a test can look at it.
 */
config.global.stubs = {
    Teleport: true,
    transition: false,
};
