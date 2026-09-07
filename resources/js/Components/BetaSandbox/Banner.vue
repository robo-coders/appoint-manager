<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The one line a beta salon carries on every screen. See BETA_SANDBOX.md.
 *
 * **What it says, and what it does not.** Two sentences: this is a preview, and
 * payments are not real. No exclamation mark, no "Welcome to the beta!", no
 * version number. It is chrome she will pass forty times a day, and the thing
 * she must never stop knowing is that the money is fake — everything else is
 * noise by the second morning.
 *
 * **A hairline notice, not a filled strip.** It was `bg-paper-sunk` with a 2px
 * accent edge, which is a warning strip: a band of colour across the top of
 * every screen, permanently, for a condition that is not an error and will not
 * change for weeks. The system composes with rules rather than with fills, and
 * the other five global notices in `AppLayout` are already hairlines on paper —
 * this was the one that was not, so it read as an alarm going off. It is the
 * same rule as theirs now, and the tag carries the identity the fill was
 * carrying.
 *
 * **The tag.** An outlined "Sandbox" mark in small caps, in the accent. That is
 * this notice's one use of it, and it is the smallest thing that can say "this
 * strip is different from the four below it" — a fill said the same thing at
 * forty times the volume. Small caps rather than ALL CAPS, for the reason
 * `.eyebrow` gives in base.css.
 *
 * **Where it sits, and why not above the nav.** The brief asked for a bar pinned
 * above the existing nav. The rail is `fixed inset-y-0 left-0` and is the full
 * height of the viewport, so a bar above it would mean giving every screen in
 * the product — for every tenant, beta or not — a top offset the rail had to be
 * inset by. That is a shell rewrite carrying a real regression risk for 100% of
 * salons in order to move a 33px bar for a handful. So it sits where the
 * product's existing global notices already sit: first in the content column,
 * above the trial, read-only and SMS bars, on every operator screen. This is a
 * recorded deviation.
 *
 * **It does not dismiss.** The mockup draws a close control on it. It is not
 * built, and that is deliberate rather than unfinished: the fact this notice
 * carries is that no card is ever charged, and a notice about fake money that
 * can be turned off is a notice that will be off on the morning somebody wonders
 * whether a payment went through.
 *
 * **It is not a permission.** `tenant.is_beta` decides whether this renders, and
 * nothing else. Every sandbox action asks the server the same question again;
 * hiding a banner has never stopped anybody POSTing to a URL.
 */
const page = usePage();

const beta = computed(() => page.props.tenant?.is_beta === true);
</script>

<template>
    <div v-if="beta" class="flex flex-wrap items-center gap-3 border-b border-b-rule px-4 py-2 text-13 md:px-8">
        <!--
            The tag's edge is `--accent-rule`, not `--accent`.

            At full strength a 20px outlined mark on warm paper is the highest-
            contrast object in a strip whose entire job is to be passed forty
            times a day without being read again — the redesign draws the border
            at a 35% wash for exactly that reason, and `--accent-rule` is this
            system's existing softened accent edge. The *type* stays full accent,
            which is what has to clear 4.5:1; a border carries no text.
        -->
        <span class="eyebrow inline-flex h-badge items-center rounded border border-accent-rule px-2 text-accent">
            Sandbox
        </span>
        <span class="text-ink-2">
            You are using a beta preview of {{ page.props.appName }}. Payments are test-only — no card is ever charged.
        </span>
        <!-- The divider the redesign sets between the two halves of this strip.
             Without it "…no card is ever charged." and the link that follows read
             as one sentence with an underlined last phrase. -->
        <span aria-hidden="true" class="hidden h-3 w-px bg-rule-strong sm:block"></span>
        <Link :href="route('beta-sandbox.show')" class="underline decoration-rule underline-offset-4">
            Sandbox tools
        </Link>
    </div>
</template>
