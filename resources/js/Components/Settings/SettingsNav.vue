<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ current: 'business' | 'branding' | 'calendar' | 'loyalty' | 'payments' | 'billing' | 'beta-sandbox' }>();

const page = usePage();

const beta = computed(() => page.props.tenant?.is_beta === true);
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
                { key: 'calendar', label: 'Calendar', href: route('settings.calendar-sync') },
                { key: 'payments', label: 'Payments', href: route('settings.payments.show') },
                { key: 'billing', label: 'Billing', href: route('settings.billing') },
                ...(beta ? [{ key: 'beta-sandbox', label: 'Beta sandbox', href: route('beta-sandbox.show') }] : []),
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
