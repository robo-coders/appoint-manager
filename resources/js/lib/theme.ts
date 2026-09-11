export const APPEARANCE_KEY = 'diarydesk.appearance';

export const THEME_PREFERENCES = ['light', 'dark', 'system'] as const;

export type ThemePreference = (typeof THEME_PREFERENCES)[number];

export type ResolvedTheme = 'light' | 'dark';

export const RETRY_MS = [400, 1200, 3000] as const;

export function isPreference(value: unknown): value is ThemePreference {
    return value === 'light' || value === 'dark' || value === 'system';
}

export function readStorage(storage: Pick<Storage, 'getItem'> | null): ThemePreference | null {
    if (!storage) return null;

    try {
        const value = storage.getItem(APPEARANCE_KEY);

        return isPreference(value) ? value : null;
    } catch {
        return null;
    }
}

export function writeStorage(preference: ThemePreference, storage: Pick<Storage, 'setItem'> | null): void {
    if (!storage) return;

    try {
        storage.setItem(APPEARANCE_KEY, preference);
    } catch {
        return;
    }
}

export function systemMedia(): MediaQueryList | null {
    try {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return null;

        return window.matchMedia('(prefers-color-scheme: dark)');
    } catch {
        return null;
    }
}

export function resolveTheme(
    preference: ThemePreference,
    media: { matches: boolean } | null,
): ResolvedTheme {
    if (preference === 'dark') return 'dark';
    if (preference === 'light') return 'light';

    return media?.matches ? 'dark' : 'light';
}

export function applyAppearance(
    preference: ThemePreference,
    media: { matches: boolean } | null,
    root: HTMLElement,
): ResolvedTheme {
    const resolved = resolveTheme(preference, media);
    root.setAttribute('data-theme', resolved);
    root.setAttribute('data-theme-preference', preference);

    const meta = root.ownerDocument.querySelector('meta[name="theme-color"]');
    if (meta) {
        const paper = root.ownerDocument.defaultView?.getComputedStyle(root).getPropertyValue('--paper').trim();
        if (paper) meta.setAttribute('content', paper);
    }

    return resolved;
}

export function listenForSystemChanges(
    getPreference: () => ThemePreference,
    apply: () => void,
    media: {
        addEventListener?: (type: 'change', listener: (event: MediaQueryListEvent) => void) => void;
        removeEventListener?: (type: 'change', listener: (event: MediaQueryListEvent) => void) => void;
        addListener?: (listener: (event: MediaQueryListEvent) => void) => void;
        removeListener?: (listener: (event: MediaQueryListEvent) => void) => void;
    } | null,
): () => void {
    if (!media) return () => {};

    const onChange = () => {
        if (getPreference() === 'system') apply();
    };

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', onChange);

        return () => media.removeEventListener?.('change', onChange);
    }

    media.addListener?.(onChange);

    return () => media.removeListener?.(onChange);
}

export async function persistPreference(
    preference: ThemePreference,
    persist: (next: ThemePreference) => Promise<unknown>,
    wait: (ms: number) => Promise<void> = delay,
): Promise<void> {
    for (let attempt = 0; ; attempt += 1) {
        try {
            await persist(preference);

            return;
        } catch {
            if (attempt >= RETRY_MS.length) return;
            await wait(RETRY_MS[attempt]);
        }
    }
}

export function delay(ms: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

export function defaultPersist(preference: ThemePreference): Promise<unknown> {
    const axios = window.axios;
    if (!axios || document.documentElement.getAttribute('data-signed-in') !== '1') {
        return Promise.resolve();
    }

    return axios.patch('/appearance', { preference });
}
