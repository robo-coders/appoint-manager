<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';

/**
 * A text field on the editorial surface.
 *
 * ── Why this is not `ui/TextInput` ────────────────────────────────────────
 *
 * `TextInput` is wired to the operator app's Tailwind theme by hand —
 * `h-control`, `rounded`, `bg-white`, `text-field`, `border-rule` — and every
 * one of those resolves through `tokens.css`. The two auth doors are drawn
 * from `.design/mockups/login/`, which is the editorial system: a 46px field
 * on the warm canvas, `--radius-slot`, `--rule-loud`, and a clay border with a
 * clay tint when the server rejects it. There is no prop that turns one into
 * the other, and adding a `surface` prop to `TextInput` would put both
 * systems' values in one component so that neither could be read off it.
 *
 * ── Why it is in `Components/ui/` anyway ──────────────────────────────────
 *
 * Because that is the rule, and the rule is right. `check:components` fails
 * any screen outside this directory that contains an `<input>`, and the point
 * of it is that a control's markup — its label association, its
 * `aria-invalid`, its `aria-describedby`, its autocomplete — exists once and
 * is got right once. A second *appearance* is a legitimate thing to have; a
 * second implementation of the accessible name is not. Appearance lives in
 * `resources/css/auth-editorial.css` with the rest of the surface.
 *
 * Nothing here blocks paste, and nothing here ever will: WCAG 2.2 AA 3.3.8
 * (Accessible Authentication) requires that a password manager can fill and a
 * person can paste.
 */
const model = defineModel<string>({ default: '' });

const props = withDefaults(
    defineProps<{
        label: string;
        type?: string;
        error?: string;
        placeholder?: string;
        autocomplete?: string;
        required?: boolean;
        autofocus?: boolean;
        /**
         * The in-field SHOW/HIDE control from the artboards.
         *
         * It is a real, focusable button rather than an icon, because on this
         * page the person typing may be reading a password out of a notebook
         * with the shop's lights off — and because a control the artboard
         * draws as a word is one a screen reader can announce as a word.
         */
        reveal?: boolean;
    }>(),
    { type: 'text', required: false, autofocus: false, reveal: false },
);

const uid = useId();
const el = ref<HTMLInputElement | null>(null);
const shown = ref(false);

/* `reveal` swaps the type, which is the only thing that actually unmasks a
   password field — nothing here re-implements masking. */
const type = computed(() => (props.reveal && shown.value ? 'text' : props.type));

onMounted(() => props.autofocus && el.value?.focus());
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <div class="ed-field">
        <label class="ed-field-label" :for="uid">
            <span>{{ label }}</span>
            <!-- "Forgot password?", "Reset it" — the artboards hang one link
                 off the right of the label rather than under the field. -->
            <slot name="action" />
        </label>

        <span class="ed-field-control">
            <input
                :id="uid"
                ref="el"
                v-model="model"
                :type="type"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? `${uid}-error` : undefined"
                class="ed-input"
                :class="[error ? 'ed-input--error' : '', reveal ? 'ed-input--revealable' : '']"
            />
            <button
                v-if="reveal"
                type="button"
                class="ed-reveal"
                :aria-label="shown ? 'Hide password' : 'Show password'"
                @click="shown = !shown"
            >
                {{ shown ? 'HIDE' : 'SHOW' }}
            </button>
        </span>

        <!--
            Under the field, which is where the artboard puts it and where a
            screen reader reaches it: `aria-describedby` above points here, so
            the message is announced as part of the field rather than as a
            stray paragraph somewhere after it.
        -->
        <span v-if="error" :id="`${uid}-error`" class="ed-field-error">{{ error }}</span>
    </div>
</template>
