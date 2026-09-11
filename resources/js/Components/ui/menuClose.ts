import type { InjectionKey } from 'vue';

export const MENU_CLOSE: InjectionKey<() => void> = Symbol('menu-close');
