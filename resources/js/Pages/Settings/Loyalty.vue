<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SaveState from '@/Components/ui/SaveState.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Loyalty packages. Off by default, and off means nothing shows anywhere.
 *
 * **One package, and the screen says so.** v1 collects a count and a reward and
 * that is the whole scheme — five sessions, next one free. The data model takes
 * more than one (`loyalty_packages` is a table with `is_active`, not a column on
 * `tenants`) so a second tier is a row rather than a migration, but a form
 * offering tiers nobody can use yet would be a promise the product does not
 * keep.
 *
 * **The definition stays on screen when the toggle is off.** It would be easy to
 * hide it, and it would mean somebody who switched the feature off to look at
 * something and switched it back on had lost what they typed. The fields are
 * disabled instead, which says the same thing and costs nothing.
 *
 * **Switching it off does not delete anybody's progress.** The count of enrolled
 * customers is on the page next to the toggle, because "twelve cards stop
 * filling" is the consequence and a toggle that does not name its consequence is
 * a toggle people flip twice.
 */
const props = defineProps<{
    loyalty: {
        enabled: boolean;
        name: string | null;
        sessions_required: number | null;
        reward: string | null;
        enrolled: number;
    };
}>();

const form = useForm({
    enabled: props.loyalty.enabled,
    name: props.loyalty.name ?? 'Loyalty card',
    sessions_required: props.loyalty.sessions_required ?? 5,
    reward: props.loyalty.reward ?? 'The next session is free',
});

const savedAt = ref<number | null>(null);

/*
 * The scheme as one sentence, from the values in the form rather than from what
 * was saved — so the person setting it up reads the thing they are about to
 * create, not the thing that is already there.
 */
const summary = computed(
    () =>
        `After ${form.sessions_required} completed appointment${form.sessions_required === 1 ? '' : 's'}, ` +
        `${(form.reward || 'the next session is free').charAt(0).toLowerCase()}` +
        `${(form.reward || 'the next session is free').slice(1)}.`,
);

const submit = () =>
    form.patch(route('settings.loyalty.update'), {
        preserveScroll: true,
        onSuccess: () => (savedAt.value = Date.now()),
    });
</script>

<template>
    <AppLayout>
        <Head title="Loyalty" />
        <PageHeader
            title="Settings"
            description="A stamp for every appointment, and one on the house when the card is full."
        />

        <SettingsNav current="loyalty" />

        <form class="mt-6 max-w-measure space-y-4" @submit.prevent="submit">
            <Toggle
                v-model="form.enabled"
                label="Loyalty packages"
                hint="Off by default. While it is off, nothing about stamps appears to you or to your customers."
            />

            <!--
                The consequence of switching it off, stated where the switch is,
                and only when there is one. See the block comment above.
            -->
            <Callout v-if="!form.enabled && loyalty.enrolled > 0" title="This pauses cards that are part-way through">
                <span class="numeral">{{ loyalty.enrolled }}</span>
                customer{{ loyalty.enrolled === 1 ? '' : 's' }} already
                {{ loyalty.enrolled === 1 ? 'has' : 'have' }} stamps. Nothing is deleted — they simply stop counting
                until this is switched back on.
            </Callout>

            <TextInput
                v-model="form.name"
                label="Package name"
                hint="For your list, not for customers. They see the count and the reward."
                :error="form.errors.name"
                :disabled="!form.enabled"
                required
            />

            <div class="grid gap-4 md:grid-cols-2">
                <TextInput
                    v-model.number="form.sessions_required"
                    type="number"
                    label="Appointments needed"
                    suffix="stamps"
                    hint="Two or more."
                    :error="form.errors.sessions_required"
                    :disabled="!form.enabled"
                    required
                />
                <TextInput
                    v-model="form.reward"
                    label="What they get"
                    hint="A few words. v1 always makes the next appointment free."
                    :error="form.errors.reward"
                    :disabled="!form.enabled"
                    required
                />
            </div>

            <p v-if="form.enabled" class="caption">{{ summary }}</p>

            <!--
                What the feature actually does, once, on the screen that turns it
                on — because nothing else in the product explains it. Deliberately
                three facts rather than a paragraph: when a customer joins, what
                earns a stamp, and what happens when the card is full.
            -->
            <section v-if="form.enabled" class="space-y-2 border-t border-t-rule pt-4">
                <h2 class="text-15">How it runs</h2>
                <ul class="space-y-1 text-13 text-ink-2">
                    <li>A customer joins on their next booking. You do not have to add anybody.</li>
                    <li>A stamp is earned when you mark an appointment as done — not when it is booked.</li>
                    <li>
                        When the card is full their next booking is free: no price, no deposit, no card. The card
                        then starts again.
                    </li>
                    <li>The count goes out on their booking confirmation text, and it is on their record here.</li>
                </ul>
            </section>

            <div class="flex items-center gap-4 pt-2">
                <Button type="submit" :loading="form.processing">Save</Button>
                <SaveState :dirty="form.isDirty" :processing="form.processing" :saved-at="savedAt" />
            </div>
        </form>
    </AppLayout>
</template>
