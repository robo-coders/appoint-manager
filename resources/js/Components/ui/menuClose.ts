import type { InjectionKey } from 'vue';

/**
 * How a `MenuItem` closes the `Menu` it is inside.
 *
 * Its own module rather than an export from `Menu.vue`, because `MenuItem`
 * importing from `Menu.vue` and `Menu.vue` rendering `MenuItem` through a slot
 * is a cycle that Vite resolves and `vue-tsc` complains about.
 */
export const MENU_CLOSE: InjectionKey<() => void> = Symbol('menu-close');
