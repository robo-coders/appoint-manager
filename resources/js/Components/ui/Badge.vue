<script setup lang="ts">
/**
 * Status at a glance, in one of two shapes.
 *
 * `outline` is the original: a hairline box with a dot, for a badge that
 * appears once or twice on a screen and has to be found.
 *
 * `solid` is the list shape, and it is what Bookings, Waitlist and Staff wear.
 * Nine outlined boxes down a column is nine boxes; a filled 6px tint gives the
 * row back and still reads as one object. There is no dot in that shape — the
 * fill is the mark, and the label was always the meaning.
 *
 * Meaning is never carried by colour alone in either shape: every tone renders
 * its label, and the fills are washes rather than solid colour, so the type is
 * read against paper whichever one it lands on.
 *
 * The `accent` tone is the one that means something rather than the one that
 * looks nicest — a booking awaiting a deposit is the single state on a list a
 * salon can act on. Solid accent puts `--accent-strong` on `--pill-accent` at
 * 5.74:1; plain `--accent` on that same fill is 4.21:1 and fails outright.
 *
 * Every solid pill is set at 500, which is what the redesign draws: a 12px
 * label on a wash is already the quietest thing in its row, and at 400 it read
 * as a caption that happened to have a box round it. Confirmed and pending share
 * a fill and separate on the row's own ink rather than on the pill's — they
 * separated on `--ink-2` at first, and that measures 4.35:1 on `--pill-neutral`:
 * under the line, on a 12px label, which is the worst place to be a little bit
 * short. `npm run check:contrast` measures all four.
 */
withDefaults(
    defineProps<{
        tone?: 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent';
        variant?: 'outline' | 'solid';
    }>(),
    { tone: 'neutral', variant: 'outline' },
);
</script>

<template>
    <span
        v-if="variant === 'solid'"
        class="inline-flex h-badge items-center whitespace-nowrap rounded px-2 text-12 font-medium"
        :class="{
            'bg-pill-neutral text-ink': tone === 'confirmed' || tone === 'pending',
            'bg-pill-accent text-accent-strong': tone === 'accent',
            'bg-pill-muted text-ink-2': tone === 'cancelled' || tone === 'neutral',
        }"
    >
        <slot />
    </span>

    <span
        v-else
        class="inline-flex h-badge items-center gap-1 whitespace-nowrap rounded border px-2 text-12"
        :class="{
            'border-rule bg-white text-ink': tone === 'confirmed',
            'border-rule bg-paper-sunk text-ink-2': tone === 'pending' || tone === 'neutral',
            'border-danger bg-white text-danger': tone === 'cancelled',
            'border-accent bg-white text-accent': tone === 'accent',
        }"
    >
        <span
            class="size-1.5 shrink-0 rounded"
            :class="{
                'bg-ink': tone === 'confirmed',
                'bg-ink-3': tone === 'pending' || tone === 'neutral',
                'bg-danger': tone === 'cancelled',
                'bg-accent': tone === 'accent',
            }"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
