import {
    applyAppearance,
    defaultPersist,
    isPreference,
    listenForSystemChanges,
    persistPreference,
    readStorage,
    resolveTheme,
    systemMedia,
    type ThemePreference,
    type ResolvedTheme,
    writeStorage,
} from '@/lib/theme';
import { readonly, ref } from 'vue';

const preference = ref<ThemePreference>('system');
const resolved = ref<ResolvedTheme>('light');

let booted = false;
let stopWatch = () => {};

function storage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function applyCurrent(): ResolvedTheme {
    const next = applyAppearance(preference.value, systemMedia(), document.documentElement);
    resolved.value = next;

    return next;
}

export function bootTheme(serverPreference?: string | null): void {
    if (booted || typeof document === 'undefined') return;

    const root = document.documentElement;
    const stored = readStorage(storage());
    const fromDom = root.getAttribute('data-theme-preference');
    const fromServer = isPreference(serverPreference) ? serverPreference : null;
    const initial = stored ?? (isPreference(fromDom) ? fromDom : null) ?? fromServer ?? 'system';

    booted = true;
    root.setAttribute('data-signed-in', '1');
    preference.value = initial;
    if (!stored) writeStorage(initial, storage());
    applyCurrent();
    stopWatch();
    stopWatch = listenForSystemChanges(
        () => preference.value,
        () => {
            applyCurrent();
        },
        systemMedia(),
    );
}

export function setPreference(next: ThemePreference): void {
    preference.value = next;
    writeStorage(next, storage());
    applyCurrent();
    void persistPreference(next, defaultPersist);
}

export function useTheme() {
    return {
        preference: readonly(preference),
        resolved: readonly(resolved),
        resolvedFrom: (pref: ThemePreference) => resolveTheme(pref, systemMedia()),
        setPreference,
    };
}

export function resetThemeForTests(): void {
    booted = false;
    stopWatch();
    stopWatch = () => {};
    preference.value = 'system';
    resolved.value = 'light';
}
