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
