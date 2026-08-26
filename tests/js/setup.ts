import { config } from '@vue/test-utils';
import { vi } from 'vitest';

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
 * Absolute in the real app, and that mattered once: `AppLayout.isCurrent()`
 * compared an absolute URL with a path and was false on every screen, so the
 * active nav item never highlighted. The stub returns absolute URLs for the
 * same reason — a stub that returned paths would make that bug untestable.
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
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps, url: (pageProps.__url as string) ?? '/' }),
    router,
    Head: { name: 'Head', render: () => null },
    Link: {
        name: 'Link',
        props: { href: { type: String, default: '#' }, method: String, as: String },
        template: '<a :href="href"><slot /></a>',
    },
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        errors: {} as Record<string, string>,
        processing: false,
        isDirty: false,
        recentlySuccessful: false,
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));

// ---------------------------------------------------------------------------
// jsdom gaps
// ---------------------------------------------------------------------------

/*
 * jsdom implements neither. `NavRail` reads `matchMedia` through `AppLayout`
 * and `Toaster` uses `ResizeObserver`; without these a component that is
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
