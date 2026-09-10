<script setup lang="ts">
import Label from './Label.vue';
import FieldError from './FieldError.vue';

/**
 * Label + control + hint/error. Every input in the product is wrapped in one of
 * these, so "every input has a visible label" is structural rather than a rule-line
 * people have to remember.
 *
 * The `error` slot replaces the rendered message with markup while `error`
 * stays the plain-text version behind `aria-describedby` — see `ui/FieldError`.
 * One field uses it: the email on `/register`, whose "already exists" message
 * carries the link to the door it is telling you about.
 */
defineProps<{
    inputId: string;
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    /**
     * Hides the label *visually* and nowhere else.
     *
     * The rule above is that every input has a label, and that stays: the
     * element is rendered, it is still `for`-associated, and a screen reader
     * still reads it. This is for the one shape where the label is already said
     * by the row — the redesign's filter strip sets a date range inline against
     * the same rule the status filters sit on, and stacking "From" over each
     * field there is what turns a one-line strip into a 90px block. Use it when
     * the surrounding row is the label; never to save vertical space on a form.
     */
    labelHidden?: boolean;
}>();
</script>

<template>
    <div class="space-y-1">
        <Label :for="inputId" :required="required" :class="labelHidden ? 'sr-only' : ''">{{ label }}</Label>
        <slot />
        <p v-if="hint && !error" class="text-12 text-ink-2">{{ hint }}</p>
        <!--
            Two branches for one component, because an *empty* provided slot is
            not the same as no slot: `<FieldError><slot name="error" /></FieldError>`
            would hand FieldError a default slot on every field in the product
            and silently replace every message with nothing.
        -->
        <FieldError v-if="$slots.error" :id="`${inputId}-error`" :message="error">
            <slot name="error" />
        </FieldError>
        <FieldError v-else :id="`${inputId}-error`" :message="error" />
    </div>
</template>
