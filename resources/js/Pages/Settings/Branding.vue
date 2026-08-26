<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SwatchGroup from '@/Components/ui/SwatchGroup.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Settings -> Branding.
 *
 * One decision on the whole screen, so the screen is built around making that
 * decision confidently rather than around filling in a form: swatches, then the
 * page they change, at the size they change it.
 *
 * Everything here is a library component. There is no allow-list entry for this
 * file in `scripts/check-components.mjs` and there must not be one.
 */
const props = defineProps<{
    /** The six preset names, read from tokens.css server-side. Never hex. */
    presets: string[];
    current: string | null;
    businessName: string;
}>();

const form = useForm({ brand_colour: props.current });

const display = (name: string) => name.charAt(0).toUpperCase() + name.slice(1);

/*
 * The preview's own `--brand`, scoped to the preview element and nowhere else.
 *
 * `undefined` rather than a value when nothing is chosen: that lets --brand
 * resolve to the ink default tokens.css sets, which is exactly what an
 * unbranded booking page does. The empty state is therefore the real thing, not
 * a drawing of it.
 *
 * This is also the ONLY place the operator app paints with a tenant's colour.
 * She is in this app forty times a day; a colour chosen for her customers
 * becomes noise as chrome. It is confined to a picture of another page.
 */
const previewStyle = computed(() =>
    form.brand_colour ? { '--brand': `var(--brand-${form.brand_colour})` } : {},
);

const initial = computed(() => props.businessName.trim().charAt(0).toUpperCase());

const chosen = computed(() =>
    form.brand_colour ? `${display(form.brand_colour)} selected.` : 'No colour selected. Your page uses the default ink.',
);

const submit = () => form.patch(route('settings.branding.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="Branding" />
        <PageHeader
            title="Branding"
            description="One colour, used in two places on your booking page. Your own screens stay as they are."
        />

        <SettingsNav current="branding" />

        <form class="mt-6 grid max-w-3xl gap-4 md:grid-cols-2" @submit.prevent="submit">
            <Card title="Colour">
                <SwatchGroup v-model="form.brand_colour" :options="presets" label="Booking page colour" />

                <!--
                    The choice in words as well as in paint. A tick on a swatch
                    is the visual cue; this is the one that survives being
                    colour-blind, and it is a single atomic status message
                    rather than a live region per swatch.
                -->
                <p class="mt-3 text-13 text-ink-2" role="status">{{ chosen }}</p>

                <p v-if="form.errors.brand_colour" class="mt-2 text-13 text-danger">{{ form.errors.brand_colour }}</p>

                <div class="mt-4 flex items-center gap-2">
                    <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save</Button>
                    <Button
                        v-if="form.brand_colour"
                        variant="ghost"
                        @click="form.brand_colour = null"
                    >
                        Use default
                    </Button>
                </div>
            </Card>

            <Card title="Preview">
                <!--
                    A picture of the booking page, not a second copy of it.

                    `inert` is what keeps it a picture: the button inside is a
                    real button and would otherwise be tabbable, clickable and
                    announced, putting a decoy primary action on a settings
                    screen. `inert` removes it from the tab order and from the
                    accessibility tree in one attribute, which is exactly the
                    intent — this is scenery.
                -->
                <div
                    class="overflow-hidden rounded border border-rule"
                    :style="previewStyle"
                    inert
                >
                    <div class="border-b border-rule bg-white px-4 py-4">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-brand text-13 font-medium text-brand-fg"
                            >{{ initial }}</span>
                            <p class="truncate text-13 font-medium">{{ businessName }}</p>
                        </div>
                    </div>
                    <div class="bg-paper p-4">
                        <p class="mb-3 text-13 text-ink-2">Thursday 4 September, 10:30</p>
                        <Button variant="brand" block>Confirm booking</Button>
                    </div>
                </div>

                <p class="mt-3 text-12 text-ink-2">
                    Your initial and the main button. Times, prices and everything else stay as they are.
                </p>
            </Card>
        </form>
    </AppLayout>
</template>
