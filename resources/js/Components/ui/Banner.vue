<script setup lang="ts">
import { toast } from '@/lib/toast';
import { Link } from '@inertiajs/vue3';
import X from 'lucide-vue-next/dist/esm/icons/x';
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'attention';
        message?: string | null;
        actionLabel?: string | null;
        actionHref?: string | null;
        actionMethod?: 'get' | 'post';
        row?: boolean;
        dismissHref?: string | null;
    }>(),
    {
        tone: 'neutral',
        message: null,
        actionLabel: null,
        actionHref: null,
        actionMethod: 'get',
        row: false,
        dismissHref: null,
    },
);

/*
 * Hidden only once the server has said so.
 *
 * The banner's real visibility is a shared prop read from the session, so the
 * honest failure mode is that a dismiss which never lands leaves the banner
 * exactly where it was. This flag is the same answer arriving a navigation
 * early — it is set from the response, never from the click, which is the
 * difference between optimistic and wrong.
 */
const hidden = ref(false);
const dismissing = ref(false);

const dismiss = async () => {
    if (!props.dismissHref || dismissing.value) return;

    dismissing.value = true;

    try {
        await window.axios.post(props.dismissHref);
        hidden.value = true;
    } catch {
        toast.error('Could not hide that notice. It will still be here next time.');
    } finally {
        dismissing.value = false;
    }
};
</script>

<template>
    <div
        v-if="!hidden"
        class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
        :class="[
            tone === 'attention' ? 'border-l-2 border-l-accent' : '',
            row || dismissHref ? 'flex flex-wrap items-center gap-3' : '',
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

        <button
            v-if="dismissHref"
            type="button"
            class="ml-auto flex h-6 w-6 shrink-0 items-center justify-center rounded text-ink-2 transition duration-fast ease-product hover:bg-ink-tint hover:text-ink"
            :disabled="dismissing"
            aria-label="Hide this notice"
            data-testid="banner-dismiss"
            @click="dismiss"
        >
            <X :size="14" :stroke-width="1.8" aria-hidden="true" />
        </button>
    </div>
</template>
