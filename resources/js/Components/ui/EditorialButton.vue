<script setup lang="ts">
import Spinner from './Spinner.vue';

/**
 * The pill button, on the editorial surface.
 *
 * `ui/Button` is 6px-radius, `h-control` tall and painted out of `tokens.css`.
 * The artboards' button is a 50px pill — `--radius-pill` — and the console's
 * is outlined where the operator's is filled. See the note beside `.ed-btn` in
 * `resources/css/auth-editorial.css` for why those two differ, and the note in
 * `EditorialField.vue` for why an appearance this different is a second
 * component rather than a prop on the first.
 *
 * The loading behaviour is `ui/Button`'s, deliberately: the label stays in
 * flow while the spinner is over it, so the button never changes width
 * mid-submit, and a *working* button keeps full contrast — only a genuinely
 * disabled one fades.
 */
withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'solid' | 'outline';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    { type: 'button', variant: 'solid', disabled: false, loading: false, block: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : undefined"
        class="ed-btn"
        :class="[`ed-btn--${variant}`, block ? 'ed-btn--block' : '']"
    >
        <span :class="loading ? 'ed-btn-label--loading' : ''"><slot /></span>
        <span v-if="loading" class="ed-btn-spinner"><Spinner /></span>
    </button>
</template>
