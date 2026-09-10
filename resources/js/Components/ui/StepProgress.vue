<script setup lang="ts">
import { computed } from 'vue';

/**
 * Where you are in setting up a business.
 *
 * Registration is not one screen — it is five, from "create an account" to
 * "when do you take bookings" — and a person doing it has never seen the
 * product. The thing they most need and least often get is an honest answer to
 * "how much more of this is there", available on every single step.
 *
 * Two shapes of the same list, because the two widths need different things:
 *
 *   - `rail` — the whole list, one step per line, each with its name. Named
 *     rather than numbered: "3 of 5" tells you how much is left, "Services"
 *     tells you what it is, and only one of those helps you decide whether to
 *     finish now. It lives in the quiet column at `md` and up.
 *   - `compact` — one line: position, name, and a hairline meter. This is the
 *     375 shape, where the rail would cost five rows of the only screen height
 *     there is. It says the same two facts in 20px.
 *
 * Not `ui/Tabs`: these are not tabs. A step you have not reached is not a panel
 * you may switch to, and a `tablist` would announce five available tabs and
 * then refuse four of them.
 *
 * `<ol>` in both shapes, because the order is the meaning. `aria-current="step"`
 * marks where you are, and each item states its own state in words — "done",
 * "you are here", "not yet" — so the state never rests on weight or position
 * alone.
 */
export type Step = { key: string; label: string };

const props = defineProps<{
    steps: Step[];
    current: string;
    /** Keys already saved. A step may be complete and behind you, or ahead. */
    completed: string[];
    variant: 'rail' | 'compact';
    /** Where a completed step links to. Omit to render the list unlinked. */
    hrefFor?: (key: string) => string;
}>();

const index = computed(() => Math.max(0, props.steps.findIndex((step) => step.key === props.current)));
const currentStep = computed(() => props.steps[index.value]);
const isDone = (key: string) => props.completed.includes(key);

/*
 * A step is reachable when it is done, or when it is the one you are on. Never
 * the ones ahead: they read from state the steps before them write, so a link
 * to step 4 from step 2 is a link to a form with nothing in it.
 */
const linkFor = (key: string) =>
    props.hrefFor && (isDone(key) || key === props.current) ? props.hrefFor(key) : undefined;

const stateOf = (key: string, at: number) => {
    if (key === props.current) return 'You are here';

    return isDone(key) ? 'Done' : `Step ${at + 1}, not yet`;
};
</script>

<template>
    <!-- The 375 shape. Two facts and a rule. -->
    <div v-if="variant === 'compact'">
        <p class="flex items-baseline justify-between gap-4 text-13">
            <span class="text-ink">{{ currentStep?.label }}</span>
            <span class="font-mono text-12 tabular-nums text-ink-2">
                {{ index + 1 }} / {{ steps.length }}
            </span>
        </p>
        <!--
            A meter, drawn as hairlines rather than as a bar. One segment per
            step, filled where it is behind you: the same vocabulary as every
            other division in this product. A filled track with a radius would
            be the one pill in a system that has no pills, and a percentage
            would be a computed number pretending to be information on a
            five-step form.

            Accent, not ink, and that is a match rather than a preference.
            Steps two to six of this flow are `Onboarding/Index.vue`, whose
            meter across the top of the page is `bg-accent` behind you and
            `bg-rule` ahead. This meter was ink and that one was clay, so the
            single thing carried across all six screens changed colour between
            screen one and screen two — which reads as two different flows.
        -->
        <div
            class="mt-2 flex gap-1"
            role="progressbar"
            :aria-valuenow="index + 1"
            aria-valuemin="1"
            :aria-valuemax="steps.length"
            :aria-valuetext="`Step ${index + 1} of ${steps.length}, ${currentStep?.label}`"
        >
            <span
                v-for="(step, at) in steps"
                :key="step.key"
                class="flex-1 border-t"
                :class="at <= index ? 'border-t-accent' : 'border-t-rule-strong'"
            />
        </div>
    </div>

    <!--
        The quiet column's shape. The whole list, named.

        The row you are on is washed in `accent-tint` — the meter's colour at
        8% — so the rail and the meter say "here" the same way. The type on it
        stays ink: `accent-tint` is specified as a fill behind ink and clay on
        clay-wash measures 4.5:1, which is a coin toss (see tokens.css).
    -->
    <ol v-else class="space-y-1">
        <li v-for="(step, at) in steps" :key="step.key">
            <component
                :is="linkFor(step.key) ? 'a' : 'span'"
                :href="linkFor(step.key)"
                class="flex min-h-row items-center gap-3 rounded px-2 text-13"
                :class="[
                    step.key === current ? 'bg-accent-tint text-ink' : 'text-ink-2',
                    linkFor(step.key) && step.key !== current
                        ? 'transition duration-fast ease-product hover:text-ink'
                        : '',
                ]"
                :aria-current="step.key === current ? 'step' : undefined"
            >
                <span class="w-4 shrink-0 font-mono text-12 tabular-nums" aria-hidden="true">
                    {{ at + 1 }}
                </span>
                <span :class="step.key === current ? 'font-medium' : ''">{{ step.label }}</span>
                <!--
                    The state in words, for a screen reader. Sighted readers get
                    it from the tint and the position; nobody should have to get
                    it from either alone.
                -->
                <span class="sr-only">{{ stateOf(step.key, at) }}</span>
            </component>
        </li>
    </ol>
</template>
