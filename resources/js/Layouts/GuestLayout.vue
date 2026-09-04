<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import StepProgress, { type Step } from '@/Components/ui/StepProgress.vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The auth surface. Every signed-out screen in the operator app is this.
 *
 * ── What the page IS ──────────────────────────────────────────────────────
 *
 * A full-bleed sheet divided by a single hairline into a working column and a
 * quiet one. Not a card on a background.
 *
 * It was `max-w-sm rounded border bg-white p-6` centred in a `min-h-screen`
 * flex — a small white box marooned in a large viewport, which is what a
 * centred div always produces and what the rest of this product spent seven
 * phases not being. A card is the shape of a page with nothing to say: it
 * borrows containment, which DESIGN.md spends once, to avoid deciding where
 * anything goes.
 *
 * So the page decides. The left column is the product's own left edge — the
 * lockup at the top, the form set against that same edge at the optical third
 * of the height, the secondary links at the foot. The right column is
 * `paper-sunk`, which is the nav rail's surface, carrying one true sentence
 * about what this is. Before a word is read, the door is made of the same
 * material as the room behind it, and the only border on the screen is the
 * hairline between them.
 *
 * ── Why it survives 375 ───────────────────────────────────────────────────
 *
 * Because it is never centred at any width. Below `lg` the right column is not
 * stacked, it is *dropped* — its sentence is orientation for a stranger, and a
 * salon owner signing in at their own door for the fortieth time is not one.
 * The left column then becomes the page: same left edge, same type sizes, same
 * vertical anchor. There is no width at which this collapses back into a box.
 *
 * `lg`, not `md`, and that is a measurement. At 768 the working column takes
 * its 560px and the quiet one gets the 208px left over, minus its own padding —
 * so the sentence set three words to a line, "The diary, / the / deposits, /
 * and the", which is a column of rag rather than a paragraph. Below 1024 the
 * page is the working column and nothing else.
 *
 * ── The vertical anchor ───────────────────────────────────────────────────
 *
 * Centred between the lockup and the foot at `md` and up; anchored from the top
 * below it, where the page scrolls and there is nothing to centre against.
 *
 * This was built top-anchored on the argument that a one-field form and a
 * five-field form should start on the same line. Rendered at 1280 that argument
 * was worth exactly nothing: the form sat in the top third, the foot sat on the
 * bottom margin, and 460px of nothing sat between them — three elements pinned
 * to three corners of an empty page. The consistency it bought was invisible,
 * because nobody ever sees Sign in and Confirm password at the same time, and
 * the dead space it cost was the first thing on screen.
 *
 * The lockup and the foot stay pinned. Only the middle centres, so the page has
 * one mass in each column rather than four things in four corners.
 */
defineProps<{
    /** The screen's own heading. 24px, ink, and the only h1 on the page. */
    title: string;
    /** One line under it. Says what happens next, never what the form is. */
    lede?: string;
    /** Hides the right column on a screen that is a dead end rather than a door. */
    quiet?: boolean;
    /**
     * Setting up a business is one flow across five screens, and the first of
     * them is on this surface. When a screen is part of it, the quiet column
     * carries the progress instead of the product sentence, and a compact form
     * of the same list appears in the working column at 375 — where the rail is
     * not there to carry it. See `OnboardingLayout`, which is the same page.
     */
    steps?: Step[];
    currentStep?: string;
    completedSteps?: string[];
}>();

const page = usePage();
</script>

<template>
    <div class="flex min-h-screen bg-paper">
        <!--
            The working column. `basis-*` rather than a max-width on a centred
            child: the column is a column, and its content aligns to its left
            edge at every width.
        -->
        <div class="flex w-full flex-col px-6 py-8 lg:basis-auth-col md:px-12 md:py-12 lg:px-16">
            <!-- Marketing is a different hostname, so this is a real navigation
                 rather than an Inertia visit. -->
            <a
                :href="page.props.urls.marketing"
                class="inline-flex w-fit rounded transition duration-fast ease-product hover:opacity-70"
            >
                <AppLogo :size="40" />
            </a>

            <div class="mt-12 w-full max-w-auth-form md:my-auto md:mt-auto">
                <StepProgress
                    v-if="steps && currentStep"
                    class="mb-8 lg:hidden"
                    variant="compact"
                    :steps="steps"
                    :current="currentStep"
                    :completed="completedSteps ?? []"
                />
                <h1 class="text-24 tracking-24">{{ title }}</h1>
                <p v-if="lede" class="mt-2 text-14 text-ink-2">{{ lede }}</p>

                <div class="mt-8">
                    <slot />
                </div>
            </div>

            <!-- The foot of the column, not the foot of the form. Pushed down
                 so it sits on the page's bottom margin at any content height. -->
            <div v-if="$slots.foot" class="mt-12 w-full max-w-auth-form pt-6 md:mt-auto">
                <slot name="foot" />
            </div>
        </div>

        <!--
            The quiet column. Empty of controls on purpose — it is the whitespace,
            given a surface so that it reads as deliberate rather than as unused.
            One sentence, which is the product's own definition of itself out of
            DESIGN.md, and nothing else.
        -->
        <aside
            v-if="!quiet"
            class="hidden border-l border-l-rule bg-paper-sunk px-12 py-12 lg:flex lg:flex-1 lg:flex-col lg:justify-center lg:px-16"
        >
            <template v-if="steps && currentStep">
                <p class="caption mb-4">Setting up</p>
                <StepProgress
                    class="-ml-2 max-w-auth-form"
                    variant="rail"
                    :steps="steps"
                    :current="currentStep"
                    :completed="completedSteps ?? []"
                />
            </template>
            <template v-else>
                <!--
                    17px, not 20. At 20 the panel headline sat two lines wide
                    opposite a 24px h1 and carried more visual mass than the
                    thing the page is for — a quiet column that outranks the
                    working one is not quiet, it is an advert. The working
                    column has to win, and at 17 it does.
                -->
                <p class="max-w-auth-form text-17 tracking-17 text-ink">
                    {{ page.props.auth_panel.headline }}
                </p>
                <p class="mt-3 max-w-auth-form text-14 leading-body text-ink-2">
                    {{ page.props.auth_panel.body }}
                </p>
            </template>
        </aside>
    </div>
</template>
