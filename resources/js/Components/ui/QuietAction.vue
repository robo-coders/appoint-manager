<script setup lang="ts">
/**
 * The quietest control in the library: an underlined phrase with a 44px tap
 * target and nothing else.
 *
 * It exists because "the least important control on the page" is a real role
 * that `Button` cannot fill — every `Button` variant, `ghost` included, is a
 * control-height box with horizontal padding, and three of those in a column
 * are three things competing for attention. `Pick another day` on the booking
 * page has to lose that competition on purpose.
 *
 * The underline, not the colour, is what makes it read as operable: `ink-2` on
 * paper is the caption colour, and a caption that happens to be clickable is
 * not discoverable. Decoration is a hairline until hover.
 *
 * Renders an `<a>` when given an `href`, because a thing that navigates should
 * be a link — middle-click, open in a new tab, and copy address all work, and
 * none of them do on a button.
 */
withDefaults(
    defineProps<{
        href?: string;
        /** Muted by default; `ink` when it is the only action in an empty state. */
        tone?: 'muted' | 'ink';
        type?: 'button' | 'submit';
    }>(),
    { tone: 'muted', type: 'button' },
);

const emit = defineEmits<{ click: [] }>();
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :href="href"
        :type="href ? undefined : type"
        class="inline-flex min-h-tap items-center px-3 text-13 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
        :class="tone === 'ink' ? 'text-ink' : 'text-ink-2 hover:text-ink'"
        @click="emit('click')"
    >
        <slot />
    </component>
</template>
