<script setup lang="ts">
/**
 * The appointment, stated.
 *
 * This is the largest thing on the page by a wide margin, and that is the whole
 * argument of the redesign: a calendar makes the customer assemble an
 * appointment out of two independent choices and hold the constraint in their
 * head while they do it. A finished appointment in 34px asks one question,
 * which has a yes and a no.
 *
 * Day and date at 34px/500, the time beneath it at 34px mono. Two lines, not
 * one: "Tuesday 10 March at 09:45" set as a single 34px paragraph wraps
 * somewhere arbitrary at 375px, and the break lands mid-phrase. Breaking it on
 * purpose puts the time on its own line, where the mono figures line up with
 * every other number on the page.
 *
 * The mockup ends the first line with "at". Rendered at 375px that only works
 * for short dates: "Wednesday 26 August at" is 22 characters and overflows 343px
 * of column at 34px, which wrapped "at" onto a line of its own — an orphan, in
 * the largest type on the page. "at" moved to the front of the time line
 * instead. The alternative was dropping below the type scale, which DESIGN.md
 * does not allow, or abbreviating the month, which makes the one sentence the
 * page exists to say read like a train timetable. See DECISIONS.md.
 *
 * The reason line above it is the phrase `AppointmentSuggester` built alongside
 * the ranking — see `ReasonKey`. It is not decoration: a proposal the customer
 * cannot check is a proposal they cannot correct.
 */
defineProps<{
    /** "Your usual Tuesday · full groom for Bramble · 90 min with Ana" */
    context: string;
    /** "Tuesday 10 March" */
    dayLabel: string;
    /** "09:45" */
    time: string;
    /** "£45.00 total, £15.00 deposit due today" */
    costLine: string;
    /** Heading level. `h1` on the booking page, `h2` when a page already has one. */
    level?: 'h1' | 'h2';
}>();
</script>

<template>
    <div>
        <p class="caption">{{ context }}</p>

        <component :is="level ?? 'h1'" class="mt-3 text-34 font-medium text-balance">
            {{ dayLabel }}<br />
            at <span class="font-mono">{{ time }}</span>
        </component>

        <p class="mt-3 text-15 text-ink-2">{{ costLine }}</p>
    </div>
</template>
