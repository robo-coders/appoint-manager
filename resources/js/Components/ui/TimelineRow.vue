<script setup lang="ts">
/**
 * One row of a day.
 *
 * This is `dashboard.html`'s timeline row, extracted so that the dashboard and
 * the diary's 375px agenda are literally the same component rather than two
 * implementations that agree today and drift next month. The brief's constraint
 * for the diary was consistency with the approved dashboard; the strongest form
 * of that is not writing it twice.
 *
 * The five tones are the five things a row can be:
 *
 * | tone | dashboard.html says | how |
 * |---|---|---|
 * | `default` | a hairline row | time, name, meta, amount |
 * | `past` | muted, no detail | `ink-2`, and `detail` is dropped |
 * | `current` | 2px ink left border, one extra line | plus a `paper-sunk` fill and medium weight |
 * | `freed` | the only coloured row | 2px accent border, an accent label, an action |
 * | `gap` | — | open time, drawn as space; see `ui/GapButton` for why |
 *
 * A row is a `<button>` when it does something and a plain row when it does
 * not, rather than a div with a click handler — so Enter works, focus is
 * visible, and a screen reader is told which rows are targets.
 */
withDefaults(
    defineProps<{
        /** `HH:MM`, local, mono, in a `--col-time` gutter. */
        time: string;
        title?: string;
        /** Muted, to the right of the title. Usually who. */
        meta?: string | null;
        /** Mono, hard right. Usually money. */
        amount?: string | null;
        /** The extra line, hanging under the title at `--sub-indent`. */
        detail?: string | null;
        tone?: 'default' | 'past' | 'current' | 'freed' | 'gap';
        /** Renders as a button and emits `open`. */
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
                interactive && tone !== 'gap' ? 'transition duration-fast ease-product' : '',
            ]"
            :aria-label="ariaLabel"
            @click="interactive && emit('open')"
        >
            <!-- Wraps. At 375px a freed row carries an accent button, and a
                 row that will not wrap squeezes "Gil Beckett cancelled, 90 min
                 open" into a four-line column beside it. -->
            <span class="flex min-h-row flex-wrap items-baseline gap-x-4 gap-y-2">
                <span
                    class="numeral w-col-time shrink-0 text-14"
                    :class="[tone === 'current' || tone === 'freed' ? 'font-medium' : '', tone === 'gap' ? 'text-ink-3' : '']"
                    >{{ time }}</span
                >

                <!--
                    `min-w-col-when` is the floor that makes the wrap happen. A
                    flex child with `min-w-0` shrinks instead of wrapping, so at
                    375px a freed row squeezed "Gil Beckett cancelled, 90 min
                    open" into a four-line column beside its button. With a
                    152px floor the button goes to the next line instead, and at
                    1280 everything is still on one.
                -->
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

            <!--
                Dropped on a past row. History does not need reading.

                The `problem` slot is *not* dropped: a double-booking or an
                overrun is not routine detail, and an appointment that has been
                and gone having clashed with another one is still something
                somebody has to deal with.
            -->
            <span v-if="detail && tone !== 'past'" class="mt-1 block pl-sub-indent text-13 text-ink-2">{{ detail }}</span>
            <span v-if="$slots.problem" class="mt-1 block pl-sub-indent text-13"><slot name="problem" /></span>
        </component>
    </li>
</template>
