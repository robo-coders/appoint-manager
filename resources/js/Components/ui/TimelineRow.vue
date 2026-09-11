<script setup lang="ts">
withDefaults(
    defineProps<{
        time: string;
        title?: string;
        meta?: string | null;
        amount?: string | null;
        detail?: string | null;
        tone?: 'default' | 'past' | 'current' | 'freed' | 'gap';
        interactive?: boolean;
        ariaLabel?: string;
    }>(),
    { tone: 'default', interactive: false },
);

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <li
        class="border-b border-b-rule"
        :class="{
            'border-l-2 border-l-ink bg-paper-sunk px-4': tone === 'current',
            'border-l-2 border-l-accent px-4': tone === 'freed',
        }"
    >
        <component
            :is="interactive ? 'button' : 'div'"
            :type="interactive ? 'button' : undefined"
            class="w-full py-3 text-left"
            :class="[
                tone === 'past' ? 'text-ink-2' : '',
                tone === 'gap' ? 'transition duration-fast ease-product hover:bg-ink-tint' : '',
                /*
                 * A row that opens something lights up under the pointer. It
                 * had the transition and nothing to transition *to*, so an
                 * interactive row looked exactly like an inert one — which is
                 * the same fault the bookings table had, one component along.
                 */
                interactive && tone !== 'gap' ? 'transition duration-fast ease-product hover:bg-paper-sunk' : '',
            ]"
            :aria-label="ariaLabel"
            @click="interactive && emit('open')"
        >
            <span class="flex min-h-row flex-wrap items-baseline gap-x-4 gap-y-2">
                <span
                    class="numeral w-col-time shrink-0 text-14"
                    :class="[tone === 'current' || tone === 'freed' ? 'font-medium' : '', tone === 'gap' ? 'text-ink-3' : '']"
                    >{{ time }}</span
                >

                <span
                    class="min-w-col-when flex-1 text-14"
                    :class="[tone === 'current' ? 'font-medium' : '', tone === 'gap' ? 'text-ink-3' : '']"
                >
                    <span v-if="tone === 'freed'" class="font-medium text-accent">Freed — </span>
                    <slot>{{ title }}</slot>
                </span>

                <span v-if="meta" class="shrink-0 text-13" :class="tone === 'past' ? '' : 'text-ink-2'">{{ meta }}</span>
                <span v-if="amount" class="numeral shrink-0 text-13">{{ amount }}</span>
                <slot name="action" />
            </span>

            <span v-if="detail && tone !== 'past'" class="mt-1 block pl-sub-indent text-13 text-ink-2">{{ detail }}</span>
            <span v-if="$slots.problem" class="mt-1 block pl-sub-indent text-13"><slot name="problem" /></span>
        </component>
    </li>
</template>
