<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * The five settings screens, as tabs.
 *
 * They were two underlined words floating above the form, which reads as
 * "here are some links" rather than "this screen has five parts". They are
 * separate routes rather than a single page with client-side tabs because each
 * one saves independently and each one deserves its own URL — a person sent a
 * link to Payments should land on Payments.
 *
 * Not `ui/Tabs`: that component owns a `tablist` and switches panels within one
 * page, and using it for navigation would announce three tabs to a screen
 * reader and then unload the page when one is chosen. These are links, so they
 * are links, and `aria-current` carries which one you are on.
 */
defineProps<{ current: 'business' | 'branding' | 'calendar' | 'loyalty' | 'payments' }>();
</script>

<template>
    <nav class="flex gap-1 border-b border-b-rule" aria-label="Settings">
        <Link
            v-for="tab in [
                { key: 'business', label: 'Business', href: route('settings.edit') },
                { key: 'branding', label: 'Branding', href: route('settings.branding.edit') },
                /*
                 * Loyalty is a tab even when it is switched off, because a tab
                 * that appears once you have turned something on is a feature
                 * nobody finds. It is between Branding and Payments rather than
                 * last: it is about what the salon offers, and Payments is where
                 * the Stripe connection lives, which is the one people arrive
                 * looking for.
                 */
                { key: 'loyalty', label: 'Loyalty', href: route('settings.loyalty.edit') },
                /*
                 * Calendar sync. Its own screen rather than a block at the
                 * bottom of Business, because it is a list of copyable links
                 * with no Save button — putting it inside a form that does have
                 * one invites people to press Save and wonder what it did.
                 */
                { key: 'calendar', label: 'Calendar', href: route('settings.calendar.show') },
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
