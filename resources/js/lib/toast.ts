import { reactive } from 'vue';

export type ToastTone = 'neutral' | 'success' | 'danger';
export type ToastItem = { id: number; message: string; tone: ToastTone; action?: { label: string; run: () => void } };

const state = reactive({ items: [] as ToastItem[] });
let seq = 0;

export function useToasts() {
    return state;
}

export function dismissToast(id: number): void {
    state.items = state.items.filter((item) => item.id !== id);
}

/**
 * A toast is a receipt, never the only place an error appears — field errors go
 * inline, next to the field they belong to.
 */
export function toast(
    message: string,
    options: { tone?: ToastTone; action?: { label: string; run: () => void }; timeout?: number } = {},
): number {
    const id = ++seq;
    state.items.push({ id, message, tone: options.tone ?? 'neutral', action: options.action });

    // A failure with something to do about it stays until dismissed.
    const timeout = options.timeout ?? (options.action ? 0 : options.tone === 'danger' ? 6000 : 2600);
    if (timeout > 0) window.setTimeout(() => dismissToast(id), timeout);

    return id;
}
