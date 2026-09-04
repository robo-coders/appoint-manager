<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { toast } from '@/lib/toast';
import { computed, ref, watch } from 'vue';

/**
 * Every tenant on the platform. Our screen, at 2am.
 *
 * It does not have to seduce anybody, so it does not: dense is the right answer
 * here and `data-density="console"` — set on the admin surface's root in
 * `app.blade.php`, and never set anywhere before this phase — is what makes the
 * rows 28px instead of 34px. Dense is not the same as sloppy, and what it was
 * was sloppy: a hand-rolled `<table>` with six unlabelled `<th>`s, five bare
 * underlined `<button>`s per row, two placeholder-only `<input>`s with no
 * labels at all, and a `plan status comped` cell that concatenated three
 * different facts into one string with spaces.
 *
 * The one idea: **this screen answers "who is in trouble" before it answers
 * anything else.** Sorted by name it answered nothing — a hundred salons in
 * alphabetical order is a directory, not a console. The default sort is now
 * whichever tenants need looking at, and the state that puts them there is
 * spelled out in words in its own column rather than inferred from three
 * booleans in a string.
 *
 * Impersonation is the dangerous one and it is treated as such: it is not a
 * link in a row of five identical links, it is behind a confirm that names the
 * salon and the person whose session is about to be borrowed. See below.
 */
type Tenant = {
    id: number;
    name: string;
    slug: string;
    plan: string;
    status: string;
    trial_ends_at: string | null;
    trial_days_left: number | null;
    is_comped: boolean;
    booking_page_live: boolean;
    bookings_this_month: number;
    last_activity_at: string | null;
    last_seen_label: string;
    owner_name: string | null;
    state: string;
    needs_attention: boolean;
    preview_url: string | null;
    booking_url: string;
    feature_flags: Record<string, boolean>;
    sms: {
        used: number;
        included: number;
        prepaid: number;
        ceiling: number;
        remaining: number;
        can_send: boolean;
        stopped: string | null;
        killed: boolean;
    };
    monthly_price: string;
    monthly_price_override_pence: number | null;
    sms_included_override: number | null;
    sms_ceiling_override: number | null;
    sms_killed: boolean;
};

const props = defineProps<{ tenants: Tenant[] }>();

const columns: Column[] = [
    { key: 'name', label: 'Salon', sortable: true, narrow: 'title' },
    { key: 'state', label: 'State', sortable: true, width: 'status', narrow: 'line' },
    { key: 'plan', label: 'Plan', sortable: true, width: 'staff', secondary: true },
    { key: 'live', label: 'Page', width: 'staff', secondary: true },
    {
        key: 'bookings_this_month',
        label: 'Bookings',
        sortable: true,
        align: 'right',
        numeric: true,
        width: 'amount',
        narrow: 'meta',
    },
    { key: 'sms_label', label: 'SMS', sortable: true, width: 'when', numeric: true, secondary: true },
    { key: 'last_seen_label', label: 'Last seen', sortable: true, width: 'when', secondary: true },
];

/*
 * Trouble first, then quiet, then everybody else — and alphabetical inside each
 * band so the list is still findable by eye. `Table` sorts itself once a column
 * header is clicked; this is only what it opens on.
 */
const rows = computed(() =>
    [...props.tenants]
        .map((tenant) => ({
            ...tenant,
            sms_label: tenant.sms.killed
                ? 'Off'
                : `${tenant.sms.used} / ${tenant.sms.included}${tenant.sms.prepaid > 0 ? ` +${tenant.sms.prepaid}` : ''}`,
        }))
        .sort((a, b) => {
            if (a.needs_attention !== b.needs_attention) return a.needs_attention ? -1 : 1;

            return a.name.localeCompare(b.name);
        }),
);

const attention = computed(() => props.tenants.filter((tenant) => tenant.needs_attention).length);

/* ----------------------------------------------------------- impersonation */

/**
 * The dangerous action on this surface, and the only one behind a confirm.
 *
 * Everything else here is reversible and ours: extending a trial, comping an
 * account, publishing a booking page. Impersonation is neither — it puts us
 * inside a real business's real diary as its owner, where every write is theirs
 * and not ours, and the audit row it leaves has that owner's name on it.
 *
 * So it is not a fifth underlined word in a row of five. It is `danger` in the
 * row menu, it names the salon *and the person* before it happens, and its
 * confirm button says what it is about to do rather than "Confirm".
 */
const impersonating = ref<Tenant | null>(null);

const startImpersonating = () => {
    const tenant = impersonating.value;
    if (!tenant) return;

    impersonating.value = null;
    router.post(route('super-admin.impersonate', tenant.id));
};

/* ------------------------------------------------------------ copy a setup */

const cloneOpen = ref(false);
const clone = useForm({ from_tenant_id: '', to_tenant_id: '' });

const submitClone = () =>
    clone.post(route('super-admin.clone'), {
        onSuccess: () => {
            clone.reset();
            cloneOpen.value = false;
        },
    });

const controlling = ref<Tenant | null>(null);
const trialDays = ref('14');
const trialEnds = ref('');
const allowance = ref('');
const ceiling = ref('');
const credit = ref('200');
const pricePence = ref('');

const openControls = (tenant: Tenant) => {
    controlling.value = tenant;
    trialDays.value = '14';
    trialEnds.value = tenant.trial_ends_at ?? '';
    allowance.value = tenant.sms_included_override === null ? '' : String(tenant.sms_included_override);
    ceiling.value = tenant.sms_ceiling_override === null ? '' : String(tenant.sms_ceiling_override);
    credit.value = '200';
    pricePence.value = tenant.monthly_price_override_pence === null ? '' : String(tenant.monthly_price_override_pence);
};

/*
 * The panel holds a copy of the row from when it opened. A save reloads the
 * tenant list but used to leave this copy stale, so "Set allowance" wrote 250
 * and the panel still said 200. Keep the open row in sync with the list.
 */
watch(
    () => props.tenants,
    (tenants) => {
        const current = controlling.value;

        if (! current) {
            return;
        }

        const fresh = tenants.find((tenant) => tenant.id === current.id);

        if (fresh) {
            controlling.value = fresh;
            allowance.value = fresh.sms_included_override === null ? '' : String(fresh.sms_included_override);
            ceiling.value = fresh.sms_ceiling_override === null ? '' : String(fresh.sms_ceiling_override);
            trialEnds.value = fresh.trial_ends_at ?? '';
            pricePence.value = fresh.monthly_price_override_pence === null ? '' : String(fresh.monthly_price_override_pence);
        }
    },
);

const copyBookingLink = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        toast('Copied.');
    } catch {
        toast('Could not copy.', { tone: 'danger' });
    }
};

const tenantById = (id: string) => props.tenants.find((tenant) => String(tenant.id) === id.trim());

const cloneFrom = computed(() => tenantById(clone.from_tenant_id));
const cloneTo = computed(() => tenantById(clone.to_tenant_id));
</script>

<template>
    <AppLayout>
        <Head title="Tenants" />

        <PageHeader
            title="Tenants"
            :description="`${tenants.length} on the platform. Every write on this screen is audited.`"
        >
            <Button variant="secondary" @click="cloneOpen = true">Copy a setup</Button>
        </PageHeader>

        <!--
            The one thing worth saying before the table. Not a stat band: this is
            a console, and a figure that is usually zero should be absent when it
            is zero rather than reserving space to say so.
        -->
        <Callout v-if="attention > 0" tone="danger" class="mb-4">
            {{ attention }} {{ attention === 1 ? 'salon needs' : 'salons need' }} looking at — expired trials,
            failed payments and lapsed subscriptions are at the top of the list.
        </Callout>

        <Table
            :columns="columns"
            :rows="rows"
            label="Tenants"
            empty-title="No tenants yet"
            empty-description="The first salon to sign up appears here."
            :row-label="(row) => `Actions for ${row.name}`"
        >
            <template #cell:name="{ row }">
                <span class="text-ink">{{ row.name }}</span>
                <span class="ml-2 font-mono text-12 text-ink-2">{{ row.slug }}</span>
            </template>

            <!--
                The state in words. It used to be `plan + status + comped` — three facts joined by spaces,
                so "trial past_due" and "pro active comped" were both one cell
                you had to parse. `Badge` carries its own label, so the meaning
                is never in the colour.
            -->
            <template #cell:state="{ row }">
                <Badge :tone="row.needs_attention ? 'cancelled' : row.state === 'Trial' ? 'pending' : 'confirmed'">
                    {{ row.state }}
                </Badge>
            </template>

            <template #cell:plan="{ row }">
                <span class="text-ink-2">{{ row.plan }}</span>
                <span v-if="row.is_comped" class="ml-1 text-ink">· comped</span>
            </template>

            <template #cell:live="{ row }">
                <span :class="row.booking_page_live ? 'text-ink' : 'text-ink-2'">
                    {{ row.booking_page_live ? 'Live' : 'Dark' }}
                </span>
            </template>

            <template #cell:last_seen_label="{ row }">
                <span class="text-ink-2">{{ row.last_seen_label }}</span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="openControls(row)">SMS, trial and price</MenuItem>
                <MenuItem @click="router.post(route('super-admin.extend-trial', row.id))">
                    Extend trial by 14 days
                </MenuItem>
                <MenuItem v-if="!row.is_comped" @click="router.post(route('super-admin.comp', row.id))">
                    Comp this account
                </MenuItem>
                <MenuItem v-if="!row.booking_page_live" @click="router.post(route('super-admin.go-live', row.id))">
                    Publish the booking page
                </MenuItem>
                <MenuItem @click="copyBookingLink(row.booking_url)">Copy booking link</MenuItem>
                <MenuItem @click="router.post(route('super-admin.preview', row.id))">Make a preview link</MenuItem>
                <MenuItem danger @click="impersonating = row">Sign in as the owner…</MenuItem>
            </template>
        </Table>

        <!--
            Names the salon and the person. "Are you sure?" would be the wrong
            question: the thing worth checking is *whose* diary, and there are a
            hundred rows in that table.
        -->
        <ConfirmDialog
            :show="impersonating !== null"
            title="Sign in as this salon's owner"
            :confirm-label="`Sign in as ${impersonating?.owner_name ?? 'the owner'}`"
            cancel-label="Stay here"
            tone="danger"
            @close="impersonating = null"
            @confirm="startImpersonating"
        >
            You will be inside <span class="text-ink">{{ impersonating?.name }}</span> as
            <span class="text-ink">{{ impersonating?.owner_name ?? 'its owner' }}</span
            >, and anything you do there is recorded against them, not you. Both the start and the end are written to
            the audit log. Their app will say you are impersonating, on every screen, until you stop.
        </ConfirmDialog>

        <SlideOver :show="controlling !== null" :title="controlling?.name ?? 'Salon'" @close="controlling = null">
            <div v-if="controlling" class="space-y-6">
                <section>
                    <h2 class="border-b border-b-rule pb-3 text-17">Texts this cycle</h2>
                    <p class="mt-3 text-14">
                        <span class="numeral font-medium">{{ controlling.sms.used }}</span>
                        of
                        <span class="numeral">{{ controlling.sms.included }}</span>
                        included
                        <span v-if="controlling.sms.prepaid > 0">
                            · <span class="numeral">{{ controlling.sms.prepaid }}</span> prepaid
                        </span>
                        · ceiling
                        <span class="numeral">{{ controlling.sms.ceiling }}</span>
                    </p>
                    <p v-if="controlling.sms.stopped" class="mt-1 text-13 text-ink-2">
                        SMS is stopped ({{ controlling.sms.stopped }}).
                    </p>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="
                            router.post(route('super-admin.sms.allowance', controlling.id), {
                                sms_included_override: allowance === '' ? null : Number(allowance),
                            })
                        "
                    >
                        <TextInput v-model="allowance" label="Included allowance" hint="Blank uses the default." mono />
                        <Button type="submit" variant="secondary">Set allowance</Button>
                    </form>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="
                            router.post(route('super-admin.sms.ceiling', controlling.id), {
                                sms_ceiling_override: ceiling === '' ? null : Number(ceiling),
                            })
                        "
                    >
                        <TextInput v-model="ceiling" label="Hard ceiling" hint="Blank uses the default." mono />
                        <Button type="submit" variant="secondary">Set ceiling</Button>
                    </form>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="
                            router.post(route('super-admin.sms.grant', controlling.id), { credits: Number(credit) })
                        "
                    >
                        <TextInput v-model="credit" label="Grant texts" hint="Does not touch Stripe." mono />
                        <Button type="submit" variant="secondary">Grant credit</Button>
                    </form>
                    <div class="mt-4">
                        <Button
                            v-if="!controlling.sms_killed"
                            variant="danger"
                            @click="router.post(route('super-admin.sms.kill', controlling.id))"
                        >
                            Stop SMS now
                        </Button>
                        <Button v-else variant="secondary" @click="router.post(route('super-admin.sms.resume', controlling.id))">
                            Allow SMS again
                        </Button>
                    </div>
                </section>

                <section>
                    <h2 class="border-b border-b-rule pb-3 text-17">Trial</h2>
                    <p class="mt-3 text-13 text-ink-2">
                        Ends {{ controlling.trial_ends_at ?? 'never' }}.
                    </p>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="router.post(route('super-admin.trial', controlling.id), { days: Number(trialDays) })"
                    >
                        <TextInput v-model="trialDays" label="Add or subtract days" hint="Negative shortens." mono />
                        <Button type="submit" variant="secondary">Change trial</Button>
                    </form>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="router.post(route('super-admin.trial', controlling.id), { ends_at: trialEnds })"
                    >
                        <TextInput v-model="trialEnds" type="date" label="Set the end date" />
                        <Button type="submit" variant="secondary">Set date</Button>
                    </form>
                    <div class="mt-4">
                        <Button variant="danger" @click="router.post(route('super-admin.trial', controlling.id), { end: true })">
                            End the trial now
                        </Button>
                    </div>
                </section>

                <section>
                    <h2 class="border-b border-b-rule pb-3 text-17">Price</h2>
                    <p class="mt-3 text-13 text-ink-2">
                        Charged at {{ controlling.monthly_price }} a month. Blank clears a founding rate.
                    </p>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="
                            router.post(route('super-admin.price', controlling.id), {
                                monthly_price_override_pence: pricePence === '' ? null : Number(pricePence),
                            })
                        "
                    >
                        <TextInput v-model="pricePence" label="Monthly price, pence" hint="2900 is £29." mono />
                        <Button type="submit" variant="secondary">Set founding price</Button>
                    </form>
                </section>
            </div>
        </SlideOver>

        <SlideOver :show="cloneOpen" title="Copy a setup" @close="cloneOpen = false">
            <form class="space-y-4" @submit.prevent="submitClone">
                <p class="text-13 text-ink-2">
                    Copies services, staff and opening hours from one salon onto another. It does not copy customers or
                    bookings. The destination keeps anything it already has.
                </p>
                <TextInput
                    v-model="clone.from_tenant_id"
                    label="Copy from"
                    hint="Tenant id."
                    mono
                    :error="clone.errors.from_tenant_id"
                />
                <p v-if="cloneFrom" class="-mt-2 text-12 text-ink">{{ cloneFrom.name }}</p>
                <TextInput
                    v-model="clone.to_tenant_id"
                    label="Copy to"
                    hint="Tenant id."
                    mono
                    :error="clone.errors.to_tenant_id"
                />
                <p v-if="cloneTo" class="-mt-2 text-12 text-ink">{{ cloneTo.name }}</p>
                <Button type="submit" :loading="clone.processing" :disabled="!cloneFrom || !cloneTo">
                    Copy the setup
                </Button>
            </form>
        </SlideOver>
    </AppLayout>
</template>
