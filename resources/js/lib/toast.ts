import { reactive } from 'vue';

export type ToastTone = 'success' | 'error';

export type ToastAction = { label: string; run: () => void };

export type ToastItem = { id: number; message: string; tone: ToastTone; action?: ToastAction };

export const DEFAULT_TOAST_DURATION_MS = 4000;

const state = reactive({
    items: [] as ToastItem[],
    durationMs: DEFAULT_TOAST_DURATION_MS,
});

const timers = new Map<number, ReturnType<typeof setTimeout>>();

let seq = 0;

export function useToasts() {
    return state;
}

export function configureToasts(durationMs?: number | null): void {
    if (typeof durationMs === 'number' && Number.isFinite(durationMs) && durationMs > 0) {
        state.durationMs = durationMs;
    }
}

export function dismissToast(id: number): void {
    const timer = timers.get(id);

    if (timer !== undefined) {
        clearTimeout(timer);
        timers.delete(id);
    }

    state.items = state.items.filter((item) => item.id !== id);
}

export function clearToasts(): void {
    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
    state.items = [];
}

function push(message: string, tone: ToastTone, action?: ToastAction): number | null {
    if (typeof message !== 'string' || message.trim() === '') {
        if (import.meta.env.DEV) {
            console.warn(`toast.${tone}() was called with no message. Nothing was shown.`);
        }

        return null;
    }

    const id = ++seq;

    state.items.push({ id, message, tone, action });

    if (action === undefined) {
        timers.set(
            id,
            setTimeout(() => dismissToast(id), state.durationMs),
        );
    }

    return id;
}

export const toast = {
    success(message: string): number | null {
        return push(message, 'success');
    },

    error(message: string, options: { actionLabel?: string; onAction?: () => void } = {}): number | null {
        const action =
            options.actionLabel !== undefined && options.onAction !== undefined
                ? { label: options.actionLabel, run: options.onAction }
                : undefined;

        return push(message, 'error', action);
    },
};
