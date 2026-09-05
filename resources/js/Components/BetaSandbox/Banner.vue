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
 * **Where it sits, and why not above the nav.** The brief asked for a bar
 * pinned above the existing nav. The rail is `fixed inset-y-0 left-0` and is
 * the full height of the viewport, so a bar above it would mean giving every
 * screen in the product — for every tenant, beta or not — a top offset the rail
 * had to be inset by. That is a shell rewrite carrying a real regression risk
 * for 100% of salons in order to move a 33px bar for a handful. So it sits
 * where the product's four existing global notices already sit: first in the
 * content column, above the trial, read-only and SMS bars, on every operator
 * screen. It reads as the top of the app because the rail is chrome, not
 * content. This is a recorded deviation.
 *
 * **The accent.** DESIGN.md rations `accent` to one meaning per screen, and
 * this spends it on a 2px left border — the brief's own suggestion, and the
 * cheapest mark that says "this strip is not the others". The border is scoped
 * (`border-l-2 border-l-accent`, `border-b border-b-rule`) rather than written
 * as `border-accent`, which would paint all four sides and give the hairline
 * below it the wrong colour — see DESIGN.md, "colouring one border side".
 *
 * **It is not a permission.** `tenant.is_beta` decides whether this renders,
 * and nothing else. Every sandbox action asks the server the same question
 * again; hiding a banner has never stopped anybody POSTing to a URL.
 */
const page = usePage();

const beta = computed(() => page.props.tenant?.is_beta === true);
</script>

<template>
    <div
        v-if="beta"
        class="border-b border-b-rule border-l-2 border-l-accent bg-paper-sunk px-4 py-2 text-13 md:px-8"
    >
        You are using a beta preview of {{ page.props.appName }}. Payments are test-only — no card is ever charged.
        <Link :href="route('beta-sandbox.show')" class="underline decoration-rule underline-offset-4">
            Sandbox tools
        </Link>
    </div>
</template>
