<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SwatchGroup from '@/Components/ui/SwatchGroup.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    presets: string[];
    current: string | null;
    businessName: string;
}>();

const form = useForm({ brand_colour: props.current });

const display = (name: string) => name.charAt(0).toUpperCase() + name.slice(1);

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
