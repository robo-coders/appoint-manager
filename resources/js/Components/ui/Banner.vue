<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        tone?: 'neutral' | 'attention';
        message?: string | null;
        actionLabel?: string | null;
        actionHref?: string | null;
        actionMethod?: 'get' | 'post';
        row?: boolean;
    }>(),
    {
        tone: 'neutral',
        message: null,
        actionLabel: null,
        actionHref: null,
        actionMethod: 'get',
        row: false,
    },
);
</script>

<template>
    <div
        class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
        :class="[
            tone === 'attention' ? 'border-l-2 border-l-accent' : '',
            row ? 'flex flex-wrap items-center gap-3' : '',
        ]"
        data-testid="banner"
        :data-tone="tone"
    >
        <slot>{{ message }}</slot>
        <template v-if="actionLabel && actionHref">
            {{ ' ' }}
            <Link
                :href="actionHref"
                :method="actionMethod"
                :as="actionMethod === 'post' ? 'button' : undefined"
                class="underline decoration-rule underline-offset-4"
            >
                {{ actionLabel }}
            </Link>
        </template>
    </div>
</template>
