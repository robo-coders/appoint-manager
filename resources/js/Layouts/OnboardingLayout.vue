<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import StepProgress, { type Step } from '@/Components/ui/StepProgress.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Steps two to five of setting up a business.
 *
 * **The same page as `GuestLayout`, deliberately.** Registration and onboarding
 * were two different-looking screens with two different progress indicators for
 * one continuous task: a white bordered header strip and a row of four bordered
 * chips above a `max-w-4xl` centred column, versus a centred card. A person
 * doing this once, in one sitting, crossed a visual seam in the middle of it
 * for no reason they could see.
 *
 * So: the same two columns, the same left edge, the same lockup on the same
 * line, and the progress carries on filling in exactly where it was. The only
 * difference is that the working column is wider here, because a week of
 * opening hours is a grid and 380px cannot hold one.
 *
 * **Back is a real control, and it does not lose anything.** Each step saves on
 * continue and `OnboardingController::show` re-reads from the database, so a
 * step you have been through comes back filled in. The rail links only to steps
 * that are done or current, for the same reason — a link to step five from step
 * two is a link to a form with nothing in it yet.
 */
const props = defineProps<{
    step: string;
    completedSteps: string[];
    steps: Step[];
    /** The step's own heading and its one line of orientation. */
    title: string;
    lede?: string;
}>();

const page = usePage();

const index = computed(() => props.steps.findIndex((step) => step.key === props.step));
const previous = computed(() => props.steps[index.value - 1]);

/*
 * `account` has no screen to go back to — it is behind you and signed. Going
 * "back" from Business details would mean the registration form, which would
 * either be a second account or a 302 straight back here.
 */
const backHref = computed(() =>
    previous.value && previous.value.key !== 'account'
        ? route('onboarding.show', { step: previous.value.key })
        : undefined,
);

const hrefFor = (key: string) =>
    key === 'account' ? route('onboarding.show') : route('onboarding.show', { step: key });
</script>

<template>
    <div class="flex min-h-screen bg-paper">
        <div class="flex w-full flex-col px-6 py-8 md:px-12 md:py-12 lg:px-16">
            <div class="flex items-center justify-between gap-4">
                <AppLogo :size="20" />
                <p class="truncate text-13 text-ink-2">{{ page.props.tenant?.name }}</p>
            </div>

            <div class="mt-12 w-full max-w-3xl md:mt-16">
                <StepProgress
                    class="mb-8 lg:hidden"
                    variant="compact"
                    :steps="steps"
                    :current="step"
                    :completed="completedSteps"
                />

                <h1 class="text-24 tracking-24">{{ title }}</h1>
                <p v-if="lede" class="mt-2 max-w-measure text-14 leading-body text-ink-2">{{ lede }}</p>

                <div class="mt-8">
                    <slot />
                </div>
            </div>

            <div class="mt-12 md:mt-auto md:pt-12">
                <Link
                    v-if="backHref"
                    :href="backHref"
                    class="inline-flex min-h-tap items-center text-13 text-ink-2 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:text-ink hover:decoration-ink"
                >
                    Back to {{ previous?.label }}
                </Link>
            </div>
        </div>

        <aside
            class="hidden shrink-0 basis-auth-col border-l border-l-rule bg-paper-sunk px-12 py-12 lg:block lg:px-16"
        >
            <p class="caption mb-4">Setting up</p>
            <StepProgress
                class="-ml-2 max-w-auth-form"
                variant="rail"
                :steps="steps"
                :current="step"
                :completed="completedSteps"
                :href-for="hrefFor"
            />
        </aside>
    </div>
</template>
