import {
    APPEARANCE_KEY,
    applyAppearance,
    isPreference,
    listenForSystemChanges,
    persistPreference,
    readStorage,
    resolveTheme,
    writeStorage,
    type ThemePreference,
} from '@/lib/theme';
import { afterEach, describe, expect, it, vi } from 'vitest';

function memoryStorage(): Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> {
    const data = new Map<string, string>();

    return {
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => {
            data.set(key, value);
        },
        removeItem: (key) => {
            data.delete(key);
        },
    };
}

afterEach(() => {
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-theme-preference');
    vi.restoreAllMocks();
});

describe('preference guards', () => {
    it('accepts only light, dark and system', () => {
        expect(isPreference('light')).toBe(true);
        expect(isPreference('dark')).toBe(true);
        expect(isPreference('system')).toBe(true);
        expect(isPreference('auto')).toBe(false);
        expect(isPreference(null)).toBe(false);
    });
});

describe('storage', () => {
    it('writes and reads the preference', () => {
        const store = memoryStorage();
        expect(readStorage(store)).toBeNull();
        writeStorage('dark', store);
        expect(readStorage(store)).toBe('dark');
        expect(store.getItem(APPEARANCE_KEY)).toBe('dark');
    });

    it('ignores a value that is not a preference', () => {
        const store = memoryStorage();
        store.setItem(APPEARANCE_KEY, 'sepia');
        expect(readStorage(store)).toBeNull();
    });

    it('survives a storage that throws', () => {
        const broken = {
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
        };

        expect(readStorage(broken)).toBeNull();
        expect(() => writeStorage('light', broken)).not.toThrow();
    });
});

describe('resolution', () => {
    it('maps light and dark straight through', () => {
        expect(resolveTheme('light', { matches: true })).toBe('light');
        expect(resolveTheme('dark', { matches: false })).toBe('dark');
    });

    it('follows matchMedia when the preference is system', () => {
        expect(resolveTheme('system', { matches: true })).toBe('dark');
        expect(resolveTheme('system', { matches: false })).toBe('light');
    });

    it('falls back to light when matchMedia is missing', () => {
        expect(resolveTheme('system', null)).toBe('light');
    });
});

describe('applying to the document', () => {
    it('sets data-theme to the resolved value and stores the preference', () => {
        const resolved = applyAppearance('system', { matches: true }, document.documentElement);

        expect(resolved).toBe('dark');
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(document.documentElement.getAttribute('data-theme-preference')).toBe('system');
    });
});

describe('system change listener', () => {
    it('re-applies when the OS theme changes and the preference is system', () => {
        const listeners: Array<(event: MediaQueryListEvent) => void> = [];
        const media = {
            addEventListener: (_type: 'change', listener: (event: MediaQueryListEvent) => void) => {
                listeners.push(listener);
            },
            removeEventListener: vi.fn(),
        };
        let preference: ThemePreference = 'system';
        const apply = vi.fn();

        const stop = listenForSystemChanges(() => preference, apply, media);
        listeners[0]({ matches: true } as MediaQueryListEvent);
        expect(apply).toHaveBeenCalledTimes(1);

        preference = 'dark';
        listeners[0]({ matches: false } as MediaQueryListEvent);
        expect(apply).toHaveBeenCalledTimes(1);

        stop();
        expect(media.removeEventListener).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when matchMedia is unsupported', () => {
        expect(() => listenForSystemChanges(() => 'system', () => {}, null)()).not.toThrow();
    });
});

describe('server persistence', () => {
    it('retries a failed write and then gives up without throwing', async () => {
        const persist = vi.fn().mockRejectedValue(new Error('network'));
        const waits: number[] = [];

        await persistPreference('dark', persist, async (ms) => {
            waits.push(ms);
        });

        expect(persist).toHaveBeenCalledTimes(4);
        expect(persist).toHaveBeenCalledWith('dark');
        expect(waits).toEqual([400, 1200, 3000]);
    });

    it('stops retrying after a successful write', async () => {
        const persist = vi
            .fn()
            .mockRejectedValueOnce(new Error('blip'))
            .mockResolvedValueOnce(undefined);
        const waits: number[] = [];

        await persistPreference('light', persist, async (ms) => {
            waits.push(ms);
        });

        expect(persist).toHaveBeenCalledTimes(2);
        expect(waits).toEqual([400]);
    });
});
