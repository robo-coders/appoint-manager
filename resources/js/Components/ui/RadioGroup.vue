<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import FieldError from './FieldError.vue';

/**
 * One choice from a small set, where the set is visible and the answer is a
 * form value that gets saved.
 *
 * **This is a real `<input type="radio">` group.** It exists because the last
 * hand-rolled control outside the library was one: `BookingModeFields` carried
 * two bare radios wearing `size-4 border-rule-strong text-ink`, typed by hand,
 * which is a fourth copy of a control the library owns everywhere else. It was
 * not a `check:components` exemption anybody had argued for — it was the file
 * the check kept naming.
 *
 * **Why not `ui/Select`.** A select hides the options behind a click, and these
 * are decisions where the *difference between the options* is the thing being
 * read — "the slot is theirs as soon as they book" against "you confirm before
 * it is theirs". A select cannot carry a second line per option, so the hint
 * would have to move out of the control and into prose above it, which is where
 * it was before and is why the question needed asking twice.
 *
 * **Why not `ui/ChoiceRow`.** That one is a `<button>` that emits `pick` and
 * draws no selected state at all, because on the booking page it lists
 * alternatives you take rather than settings you hold. A control whose whole
 * job is to show which of two things is currently true needs to show it.
 *
 * **Why not `ui/Toggle` even for two options.** A toggle is one thing that is on
 * or off, and it takes effect immediately. Two named alternatives that are saved
 * with the rest of a form are not that shape; "off" would have to mean
 * "requests", which is a name nobody chose.
 *
 * Structure: a real `<fieldset>` with a real `<legend>`, hairline rows, and the
 * error below the group linked to the fieldset with `aria-describedby` —
 * matching `ui/Field`, except that the error belongs to the whole group here
 * rather than to any one input in it.
 */
const model = defineModel<string>({ required: true });

const props = defineProps<{
    /** The question. Rendered as the group's `<legend>`. */
    legend: string;
    /**
     * `sample` is an example of what the option produces, set in mono under
     * the hint — an event line for a calendar feed, a rendered message for a
     * reminder. It exists because some of these choices are only legible as
     * their output: "customer names and service details" and "Busy blocks"
     * are a paragraph each to describe and one line each to show.
     */
    options: Array<{ value: string; label: string; hint?: string; sample?: string }>;
    /**
     * The radios' shared `name`. Defaults to a generated one, which is correct
     * for an Inertia form; pass it only when a real HTML form post needs the
     * key to be a particular string.
     */
    name?: string;
    error?: string;
    disabled?: boolean;
    /**
     * Hides the legend *visually* and nowhere else, the way `ui/Field`'s
     * `labelHidden` does. For a group that already sits under a section
     * heading saying the same words: two of them is the heading twice, and
     * dropping the `<legend>` instead would leave the fieldset unnamed.
     */
    legendHidden?: boolean;
    /**
     * Marks the group required, the way `ui/Label` marks a field.
     *
     * The marker is ink and `aria-hidden`, identical to `ui/Label`'s, because
     * the alternative on `/register` was a form of six required answers where
     * five carried a marker and the sixth — the one people skip — did not.
     */
    required?: boolean;
}>();

const uid = useId();
const groupName = computed(() => props.name ?? `radio-${uid}`);

/*
 * `focus()`, so a page validating on submit can send the caret to this group
 * the way it can to any `ui/TextInput`. The chosen radio if there is one, the
 * first otherwise — which is what a browser does when it moves into a radio
 * group by keyboard, and what makes "you have not answered this" land on the
 * thing that has not been answered rather than nowhere.
 */
const el = ref<HTMLFieldSetElement | null>(null);

defineExpose({
    focus: () => {
        const group = el.value;

        if (!group) return;

        const target =
            group.querySelector<HTMLInputElement>('input[type="radio"]:checked') ??
            group.querySelector<HTMLInputElement>('input[type="radio"]');

        target?.focus();
    },
});
</script>

<template>
    <fieldset ref="el" class="space-y-3" :aria-describedby="error ? `${uid}-error` : undefined">
        <!-- prettier-ignore -->
        <legend class="caption" :class="legendHidden ? 'sr-only' : ''">{{ legend }}<span v-if="required" class="text-ink" aria-hidden="true">*</span></legend>

        <!--
            The row is the label, so the whole hairline strip is the tap target
            rather than the 16px circle at the left of it. `items-start` because
            an option with a hint is two lines tall and the control belongs
            beside the first of them.
        -->
        <label
            v-for="option in options"
            :key="option.value"
            class="flex min-h-row items-start gap-3 border-b border-b-rule py-3"
            :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'"
        >
            <input
                v-model="model"
                type="radio"
                :name="groupName"
                :value="option.value"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                class="mt-0.5 size-4 shrink-0 border-rule-strong bg-paper-sunk text-ink disabled:cursor-not-allowed"
            />
            <span class="min-w-0">
                <span class="block text-13" :class="disabled ? 'text-ink-2' : 'text-ink'">{{ option.label }}</span>
                <span v-if="option.hint" class="mt-0.5 block text-12 text-ink-2">{{ option.hint }}</span>
                <span v-if="option.sample" class="mt-2 block font-mono text-12 text-ink-2">{{ option.sample }}</span>
            </span>
        </label>

        <FieldError :id="`${uid}-error`" :message="error" />
        <slot />
    </fieldset>
</template>
