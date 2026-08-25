import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue';

const SELECTOR =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Traps Tab inside a panel while it is open, and returns focus to whatever was
 * focused before it opened. Shared by Modal, SlideOver and ConfirmDialog so all
 * three behave identically.
 */
export function useFocusTrap(panel: Ref<HTMLElement | null>, open: Ref<boolean>, onEscape: () => void) {
    let previous: HTMLElement | null = null;

    const items = () => Array.from(panel.value?.querySelectorAll<HTMLElement>(SELECTOR) ?? []);

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            return onEscape();
        }
        if (event.key !== 'Tab') return;

        const focusable = items();
        if (focusable.length === 0) return event.preventDefault();

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const release = () => {
        document.removeEventListener('keydown', onKeydown, true);
        document.body.style.removeProperty('overflow');
        previous?.focus();
        previous = null;
    };

    watch(
        open,
        async (isOpen) => {
            if (isOpen) {
                previous = document.activeElement as HTMLElement | null;
                document.addEventListener('keydown', onKeydown, true);
                document.body.style.setProperty('overflow', 'hidden');
                await nextTick();
                (items()[0] ?? panel.value)?.focus();
            } else if (previous) {
                release();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => open.value && release());
}
