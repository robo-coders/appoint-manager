import { config } from '@vue/test-utils';
import { vi } from 'vitest';
import { reactive } from 'vue';

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

config.global.mocks = { route: routeStub };
config.global.provide = { route: routeStub };

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

config.global.stubs = {
    Teleport: true,
    transition: false,
};
