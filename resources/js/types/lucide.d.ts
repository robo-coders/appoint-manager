/**
 * Types for lucide's per-icon deep imports.
 *
 * The package ships one `.d.ts` for its barrel and none for
 * `dist/esm/icons/<name>.js`, so a deep import is `any` under `strict` and
 * `vue-tsc` fails the build. Declaring the pattern once here is the whole fix,
 * and it keeps the deep imports — which are the point; see `lib/navIcons`.
 *
 * Typed as `DefineComponent` with lucide's real prop shape rather than `any`,
 * so passing `:size="'large'"` is still an error.
 */
declare module 'lucide-vue-next/dist/esm/icons/*' {
    import type { DefineComponent } from 'vue';

    const icon: DefineComponent<{
        size?: number | string;
        color?: string;
        strokeWidth?: number | string;
        absoluteStrokeWidth?: boolean;
    }>;

    export default icon;
}
