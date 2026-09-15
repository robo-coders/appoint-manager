<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Stat from '@/Components/ui/Stat.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { toast } from '@/lib/toast';
import Download from 'lucide-vue-next/dist/esm/icons/download';
import Rows3 from 'lucide-vue-next/dist/esm/icons/rows-3';
import Store from 'lucide-vue-next/dist/esm/icons/store';
import { computed, onMounted, ref, watch } from 'vue';

type Tenant = {
    id: number;
    name: string;
    slug: string;
    plan: string;
    status: string;
    trial_ends_at: string | null;
    trial_days_left: number | null;
    is_comped: boolean;
    is_beta: boolean;
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

type PlatformStats = {
    live_tenants: number;
    bookings_today: number;
    sms_this_month: number;
    send_failures: number;
};

const props = defineProps<{ tenants: Tenant[]; stats: PlatformStats }>();

const number = new Intl.NumberFormat('en-GB');

const columns: Column[] = [
    { key: 'display_name', label: 'Salon', sortable: true, narrow: 'title' },
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

const UNNAMED = 'Unnamed salon';

const initials = (name: string) =>
    (name.match(/[\p{L}\p{N}]+/gu) ?? [])
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join('');

const salonName = (tenant: { name: string }) => tenant.name.trim() || UNNAMED;

const rows = computed(() =>
    [...props.tenants]
        .map((tenant) => ({
            ...tenant,
            display_name: salonName(tenant),
            unnamed: tenant.name.trim() === '',
            initials: initials(tenant.name),
            sms_label: tenant.sms.killed
                ? 'Off'
                : `${tenant.sms.used} / ${tenant.sms.included}${tenant.sms.prepaid > 0 ? ` +${tenant.sms.prepaid}` : ''}`,
        }))
        .sort((a, b) => {
            if (a.needs_attention !== b.needs_attention) return a.needs_attention ? -1 : 1;

            return a.display_name.localeCompare(b.display_name);
        }),
);

const attention = computed(() => props.tenants.filter((tenant) => tenant.needs_attention).length);

const STATE_TONES: Record<string, 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent'> = {
    Subscribed: 'confirmed',
    Comped: 'accent',
    Trial: 'pending',
    'Payment failed': 'cancelled',
    Cancelled: 'cancelled',
    'Trial over': 'cancelled',
    Paused: 'neutral',
    'No plan': 'neutral',
};

const toneFor = (tenant: Tenant) => STATE_TONES[tenant.state] ?? (tenant.needs_attention ? 'cancelled' : 'neutral');

const bookingDomain = (url: string) => url.replace(/^https?:\/\//, '').replace(/\/$/, '');

const STATE_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'live', label: 'Live' },
    { value: 'trial', label: 'Trial' },
    { value: 'attention', label: 'Needs attention' },
];

const query = ref('');
const stateFilter = ref('all');
const planFilter = ref('');

const planOptions = computed(() => [
    { value: '', label: 'Every plan' },
    ...[...new Set(props.tenants.map((tenant) => tenant.plan))]
        .sort()
        .map((plan) => ({ value: plan, label: plan })),
]);

const matchesState = (tenant: Tenant) =>
    ({
        all: true,
        live: tenant.booking_page_live,
        trial: tenant.state === 'Trial',
        attention: tenant.needs_attention,
    })[stateFilter.value] ?? true;

const matchesQuery = (tenant: Tenant) => {
    const term = query.value.trim().toLowerCase();

    if (term === '') return true;

    return [salonName(tenant), tenant.slug, tenant.owner_name, tenant.booking_url]
        .some((field) => (field ?? '').toLowerCase().includes(term));
};

const visibleRows = computed(() =>
    rows.value.filter(
        (row) =>
            matchesState(row) && matchesQuery(row) && (planFilter.value === '' || row.plan === planFilter.value),
    ),
);

const DENSITY_KEY = 'console.density';
const compact = ref(true);

const applyDensity = () => {
    if (compact.value) {
        document.body.dataset.density = 'console';

        return;
    }

    delete document.body.dataset.density;
};

const toggleDensity = () => {
    compact.value = !compact.value;
    applyDensity();

    try {
        window.localStorage.setItem(DENSITY_KEY, compact.value ? 'console' : 'default');
    } catch {}
};

onMounted(() => {
    try {
        if (window.localStorage.getItem(DENSITY_KEY) === 'default') compact.value = false;
    } catch {}

    applyDensity();
});

const EXPORT_COLUMNS: Array<{ label: string; value: (tenant: Tenant) => string }> = [
    { label: 'Salon', value: (tenant) => salonName(tenant) },
    { label: 'Slug', value: (tenant) => tenant.slug },
    { label: 'Owner', value: (tenant) => tenant.owner_name ?? '' },
    { label: 'State', value: (tenant) => tenant.state },
    { label: 'Plan', value: (tenant) => tenant.plan },
    { label: 'Booking page', value: (tenant) => (tenant.booking_page_live ? 'Live' : 'Dark') },
    { label: 'Bookings this month', value: (tenant) => String(tenant.bookings_this_month) },
    { label: 'SMS used', value: (tenant) => String(tenant.sms.used) },
    { label: 'SMS included', value: (tenant) => String(tenant.sms.included) },
    { label: 'Monthly price', value: (tenant) => tenant.monthly_price },
    { label: 'Last seen', value: (tenant) => tenant.last_seen_label },
];

const cell = (value: string) => `"${value.replace(/"/g, '""')}"`;

const exportCsv = () => {
    const rows = [
        EXPORT_COLUMNS.map((column) => cell(column.label)),
        ...visibleRows.value.map((tenant) => EXPORT_COLUMNS.map((column) => cell(column.value(tenant)))),
    ];

    const blob = new Blob([rows.map((row) => row.join(',')).join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `tenants-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
};

const impersonating = ref<Tenant | null>(null);

const startImpersonating = () => {
    const tenant = impersonating.value;
    if (!tenant) return;

    impersonating.value = null;
    router.post(route('super-admin.impersonate', tenant.id));
};

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
const beta = ref(false);

const openControls = (tenant: Tenant) => {
    controlling.value = tenant;
    beta.value = tenant.is_beta;
    trialDays.value = '14';
    trialEnds.value = tenant.trial_ends_at ?? '';
    allowance.value = tenant.sms_included_override === null ? '' : String(tenant.sms_included_override);
    ceiling.value = tenant.sms_ceiling_override === null ? '' : String(tenant.sms_ceiling_override);
    credit.value = '200';
    pricePence.value = tenant.monthly_price_override_pence === null ? '' : String(tenant.monthly_price_override_pence);
};

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
            beta.value = fresh.is_beta;
            pricePence.value = fresh.monthly_price_override_pence === null ? '' : String(fresh.monthly_price_override_pence);
        }
    },
);

const copyBookingLink = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        toast.success('Link copied');
    } catch {
        toast.error('Could not copy the link');
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
            <Button variant="secondary" @click="exportCsv">
                <Download :size="15" :stroke-width="1.8" aria-hidden="true" />
                Export
            </Button>
            <Button @click="cloneOpen = true">Copy a setup</Button>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
            <Stat label="Live tenants" :value="number.format(stats.live_tenants)" />
            <Stat label="Bookings today" :value="number.format(stats.bookings_today)" />
            <Stat label="SMS this month" :value="number.format(stats.sms_this_month)" />
            <Stat
                label="Send failures"
                :value="number.format(stats.send_failures)"
                :hint="stats.send_failures > 0 ? 'Needs review' : undefined"
                :hint-tone="stats.send_failures > 0 ? 'accent' : 'muted'"
                :emphasis="stats.send_failures > 0"
            />
        </div>

        <Callout v-if="attention > 0" tone="danger" class="mb-4">
            {{ attention }} {{ attention === 1 ? 'salon needs' : 'salons need' }} looking at — expired trials,
            failed payments and lapsed subscriptions are at the top of the list.
        </Callout>

        <Tabs v-model="stateFilter" :tabs="STATE_FILTERS" variant="filter" label="Filter tenants" class="mb-4">
            <template #end>
                <TextInput
                    v-model="query"
                    label="Search tenants"
                    label-hidden
                    placeholder="Search salons, owners, domains"
                    class="w-64 max-w-full"
                />
                <Select v-model="planFilter" label="Plan" label-hidden :options="planOptions" class="w-40" />
                <Button
                    variant="secondary"
                    :aria-pressed="compact"
                    :title="compact ? 'Roomier rows' : 'Tighter rows'"
                    @click="toggleDensity"
                >
                    <Rows3 :size="15" :stroke-width="1.8" aria-hidden="true" />
                    <span class="sr-only">{{ compact ? 'Roomier rows' : 'Tighter rows' }}</span>
                </Button>
            </template>
        </Tabs>

        <Table
            :columns="columns"
            :rows="visibleRows"
            label="Tenants"
            :empty-title="tenants.length === 0 ? 'No tenants yet' : 'Nothing matches those filters'"
            :empty-description="
                tenants.length === 0
                    ? 'The first salon to sign up appears here.'
                    : 'Clear the search or widen the filters to see more.'
            "
            :row-label="(row) => `Actions for ${row.display_name}`"
        >
            <template #cell:display_name="{ row }">
                <span class="flex items-center gap-2">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-ink-tint text-12 font-medium text-ink-2"
                        aria-hidden="true"
                    >
                        <template v-if="row.initials">{{ row.initials }}</template>
                        <Store v-else :size="14" :stroke-width="1.75" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate" :class="row.unnamed ? 'italic text-ink-2' : 'text-ink'">{{
                            row.display_name
                        }}</span>
                        <span class="block truncate font-mono text-12 text-ink-2">{{
                            bookingDomain(row.booking_url)
                        }}</span>
                    </span>
                </span>
            </template>

            <template #cell:state="{ row }">
                <Badge :tone="toneFor(row)">{{ row.state }}</Badge>
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

        <ConfirmDialog
            :show="impersonating !== null"
            title="Sign in as this salon's owner"
            :confirm-label="`Sign in as ${impersonating?.owner_name ?? 'the owner'}`"
            cancel-label="Stay here"
            tone="danger"
            @close="impersonating = null"
            @confirm="startImpersonating"
        >
            You will be inside <span class="text-ink">{{ impersonating ? salonName(impersonating) : '' }}</span> as
            <span class="text-ink">{{ impersonating?.owner_name ?? 'its owner' }}</span
            >, and anything you do there is recorded against them, not you. Both the start and the end are written to
            the audit log. Their app will say you are impersonating, on every screen, until you stop.
        </ConfirmDialog>

        <SlideOver
            :show="controlling !== null"
            :title="controlling ? salonName(controlling) : 'Salon'"
            @close="controlling = null"
        >
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

                <section>
                    <h2 class="border-b border-b-rule pb-3 text-17">Beta sandbox</h2>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="router.post(route('super-admin.beta', controlling.id), { is_beta: beta })"
                    >
                        <Checkbox
                            v-model="beta"
                            label="In the beta programme"
                            hint="Pins this salon to Stripe test mode and gives it the sandbox tools in Settings."
                        />
                        <Button type="submit" variant="secondary">Save</Button>
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
                <p v-if="cloneFrom" class="-mt-2 text-12 text-ink">{{ salonName(cloneFrom) }}</p>
                <TextInput
                    v-model="clone.to_tenant_id"
                    label="Copy to"
                    hint="Tenant id."
                    mono
                    :error="clone.errors.to_tenant_id"
                />
                <p v-if="cloneTo" class="-mt-2 text-12 text-ink">{{ salonName(cloneTo) }}</p>
                <Button type="submit" :loading="clone.processing" :disabled="!cloneFrom || !cloneTo">
                    Copy the setup
                </Button>
            </form>
        </SlideOver>
    </AppLayout>
</template>
