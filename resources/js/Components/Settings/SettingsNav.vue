<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * The three settings screens, as tabs.
 *
 * They were two underlined words floating above the form, which reads as
 * "here are some links" rather than "this screen has three parts". They are
 * separate routes rather than a single page with client-side tabs because each
 * one saves independently and each one deserves its own URL — a person sent a
 * link to Payments should land on Payments.
 *
 * Not `ui/Tabs`: that component owns a `tablist` and switches panels within one
 * page, and using it for navigation would announce three tabs to a screen
 * reader and then unload the page when one is chosen. These are links, so they
 * are links, and `aria-current` carries which one you are on.
 */
defineProps<{ current: 'business' | 'branding' | 'payments' }>();
</script>

<template>
    <nav class="flex gap-1 border-b border-b-rule" aria-label="Settings">
        <Link
            v-for="tab in [
                { key: 'business', label: 'Business', href: route('settings.edit') },
                { key: 'branding', label: 'Branding', href: route('settings.branding.edit') },
                { key: 'payments', label: 'Payments', href: route('settings.payments.show') },
            ]"
            :key="tab.key"
            :href="tab.href"
            class="-mb-px border-b-2 px-3 py-2 text-13 transition duration-fast ease-product"
            :class="
                current === tab.key
                    ? 'border-b-ink text-ink'
                    : 'border-b-transparent text-ink-2 hover:border-b-rule-strong hover:text-ink'
            "
            :aria-current="current === tab.key ? 'page' : undefined"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
