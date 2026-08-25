<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

import Specimen from './Specimen.vue';
import State from './State.vue';

import AppLogo from '@/Components/AppLogo.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Card from '@/Components/ui/Card.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import Countdown from '@/Components/ui/Countdown.vue';
import DatePicker from '@/Components/ui/DatePicker.vue';
import DateTime from '@/Components/ui/DateTime.vue';
import Duration from '@/Components/ui/Duration.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import KeyHint from '@/Components/ui/KeyHint.vue';
import Label from '@/Components/ui/Label.vue';
import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import Modal from '@/Components/ui/Modal.vue';
import Money from '@/Components/ui/Money.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Select from '@/Components/ui/Select.vue';
import Skeleton from '@/Components/ui/Skeleton.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Spinner from '@/Components/ui/Spinner.vue';
import Stat from '@/Components/ui/Stat.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import TimePicker from '@/Components/ui/TimePicker.vue';
import Toaster from '@/Components/ui/Toaster.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import { toast } from '@/lib/toast';

const density = ref<'compact' | 'roomy' | 'console'>('compact');

const text = ref('Willow Street Grooming');
const empty = ref('');
const area = ref('Nervous around clippers. Muzzle in the bag.');
const choice = ref('full-groom');
const zone = ref('Europe/London');
const day = ref('2026-03-10');
const time = ref('09:00');
const checked = ref(true);
const toggled = ref(true);
const tab = ref('upcoming');
const page = ref(2);

const modal = ref(false);
const slide = ref(false);
const confirm = ref(false);
const loadingTable = ref(false);

const zones = [
    { value: 'Europe/London', label: 'Europe/London' },
    { value: 'Europe/Dublin', label: 'Europe/Dublin' },
    { value: 'Europe/Paris', label: 'Europe/Paris' },
    { value: 'America/New_York', label: 'America/New_York' },
    { value: 'Australia/Adelaide', label: 'Australia/Adelaide' },
];

const columns: Column[] = [
    { key: 'customer', label: 'Client', sortable: true },
    { key: 'service', label: 'Service', secondary: true },
    { key: 'starts_at', label: 'When', numeric: true, sortable: true, align: 'right' },
    { key: 'duration', label: 'Length', numeric: true, align: 'right', secondary: true },
    { key: 'price', label: 'Price', numeric: true, align: 'right', sortable: true },
];

const rows = [
    { id: 1, customer: 'Priya Raman', service: 'Full groom', starts_at: '09:00', duration: 60, price: 4500, status: 'confirmed' },
    { id: 2, customer: 'Tom Whitfield', service: 'Bath and blow dry', starts_at: '10:30', duration: 45, price: 2800, status: 'pending' },
    { id: 3, customer: 'Aisha Bello', service: 'Nail clip', starts_at: '12:15', duration: 15, price: 1200, status: 'cancelled' },
];

// Mirrors what the server's Money cast sends, separators and all.
const money = (pence: number) => ({
    amount: pence,
    currency: 'GBP',
    formatted: new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(pence / 100),
});

const soon = new Date(Date.now() + 9 * 60 * 1000).toISOString();
const nav = [
    'Identity', 'Buttons', 'Text input', 'Textarea', 'Select', 'Combobox', 'Date and time',
    'Checkbox', 'Toggle', 'Table', 'Badge', 'Numbers', 'Countdown', 'Stat', 'Tabs',
    'Pagination', 'Menu', 'Modal', 'Slide over', 'Confirm dialog', 'Toast', 'Callout',
    'Empty state', 'Skeleton', 'Spinner', 'Card', 'Page header', 'Label', 'Key hint',
];
const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-');
</script>

<template>
    <Head title="Components" />

    <div :data-density="density" class="min-h-screen bg-paper text-ink">
        <header class="sticky top-0 z-20 border-b border-rule bg-paper">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-3">
                <div class="flex items-center gap-3">
                    <AppLogo :size="18" />
                    <span class="caption">Component gallery</span>
                </div>
                <div class="flex items-center gap-2">
                    <Label>Density</Label>
                    <div class="flex items-center gap-1">
                        <button
                            v-for="option in (['compact', 'roomy', 'console'] as const)"
                            :key="option"
                            type="button"
                            class="h-8 rounded border px-2 text-12 transition duration-fast ease-product"
                            :class="
                                density === option
                                    ? 'border-ink bg-ink-tint text-ink'
                                    : 'border-rule text-ink-2 hover:text-ink'
                            "
                            @click="density = option"
                        >
                            {{ option }}
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto flex max-w-6xl gap-8 px-6 py-8">
            <nav class="sticky top-20 hidden h-fit w-44 shrink-0 lg:block">
                <p class="caption mb-2">On this page</p>
                <ul class="space-y-0.5">
                    <li v-for="item in nav" :key="item">
                        <a
                            :href="`#${slug(item)}`"
                            class="block py-0.5 text-13 text-ink-2 transition duration-fast ease-product hover:text-ink"
                            >{{ item }}</a
                        >
                    </li>
                </ul>
            </nav>

            <main class="min-w-0 flex-1 space-y-8">
                <div>
                    <h1 class="text-24">Components</h1>
                    <p class="mt-1 max-w-measure text-14 text-ink-2">
                        Every shared component, in every state. Switch the density above to see the same components as
                        the operator app, the public booking page and the super admin console render them.
                    </p>
                </div>

                <Specimen name="Identity" note="The mark alone, and locked up with the wordmark.">
                    <State name="Lockup"><AppLogo :size="24" /></State>
                    <State name="Mark only"><AppLogo :size="24" variant="mark" /></State>
                    <State name="At favicon size"><AppLogo :size="16" variant="mark" /></State>
                </Specimen>

                <Specimen name="Buttons" note="At most one primary per screen — that is where the accent lives.">
                    <State name="Variants">
                        <div class="flex flex-wrap items-center gap-2">
                            <Button>Confirm booking</Button>
                            <Button variant="secondary">Cancel</Button>
                            <Button variant="ghost">Skip</Button>
                            <Button variant="danger">Delete service</Button>
                        </div>
                    </State>
                    <State name="Loading — the label is replaced but the width holds">
                        <div class="flex flex-wrap items-center gap-2">
                            <Button loading>Confirm booking</Button>
                            <Button variant="secondary" loading>Cancel</Button>
                        </div>
                    </State>
                    <State name="Disabled">
                        <div class="flex flex-wrap items-center gap-2">
                            <Button disabled>Confirm booking</Button>
                            <Button variant="secondary" disabled>Cancel</Button>
                        </div>
                    </State>
                    <State name="Block — the public booking page"><Button block>Pay £10 deposit</Button></State>
                    <State name="As a link"><Button href="#buttons" variant="secondary">Back to services</Button></State>
                </Specimen>

                <Specimen name="Text input" note="Label, hint and error are part of the component, not the caller's job.">
                    <State name="Default"><TextInput v-model="text" label="Business name" /></State>
                    <State name="With hint">
                        <TextInput v-model="empty" label="Phone" type="tel" hint="We only use this to text about bookings." />
                    </State>
                    <State name="Error — inline, linked by aria-describedby">
                        <TextInput v-model="empty" label="Email" type="email" error="Enter a valid email address." />
                    </State>
                    <State name="Prefix and suffix, mono">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <TextInput v-model="empty" label="Price" prefix="£" mono placeholder="45.00" />
                            <TextInput v-model="empty" label="Duration" suffix="min" mono placeholder="60" />
                        </div>
                    </State>
                    <State name="Disabled"><TextInput v-model="text" label="Slug" disabled /></State>
                </Specimen>

                <Specimen name="Textarea">
                    <State name="Default"><Textarea v-model="area" label="Notes" /></State>
                    <State name="Error"><Textarea v-model="empty" label="Notes" error="Keep this under 1000 characters." /></State>
                </Specimen>

                <Specimen name="Select">
                    <State name="Default">
                        <Select
                            v-model="choice"
                            label="Service"
                            :options="[
                                { value: 'full-groom', label: 'Full groom' },
                                { value: 'bath', label: 'Bath and blow dry' },
                                { value: 'nails', label: 'Nail clip' },
                            ]"
                        />
                    </State>
                    <State name="Error"><Select v-model="empty" label="Staff" error="Choose who is doing this." /></State>
                </Specimen>

                <Specimen name="Combobox" note="A select you can type into. 400 timezones is not a dropdown.">
                    <State name="Closed"><Combobox v-model="zone" label="Timezone" :options="zones" /></State>
                </Specimen>

                <Specimen name="Date and time">
                    <State name="Pickers">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <DatePicker v-model="day" label="Date" />
                            <TimePicker v-model="time" label="Start time" />
                        </div>
                    </State>
                    <State name="Display — absolute, in tenant time, mono">
                        <div class="space-y-1 text-14">
                            <p><DateTime value="2026-03-10T09:00:00Z" /></p>
                            <p><DateTime value="2026-03-10T09:00:00Z" date-only /></p>
                            <p><DateTime value="2026-03-10T09:00:00Z" time-only /></p>
                            <p><DateTime :value="soon" relative /></p>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Checkbox">
                    <State name="States">
                        <div class="space-y-3">
                            <Checkbox v-model="checked" label="Send a reminder 48 hours before" />
                            <Checkbox v-model="checked" label="Commit" hint="Otherwise this is a dry run." />
                            <Checkbox v-model="checked" label="Disabled" disabled />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Toggle">
                    <State name="States">
                        <div class="max-w-sm space-y-4">
                            <Toggle v-model="toggled" label="Take deposits" hint="Requires a connected Stripe account." />
                            <Toggle v-model="toggled" label="Disabled" disabled />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Table" note="Hairline rows, square corners, no zebra. Numbers right-aligned and mono.">
                    <State name="Populated, sortable, with row actions">
                        <Table :columns="columns" :rows="rows">
                            <template #cell:starts_at="{ row }"><DateTime :value="String(row.starts_at)" time-only /></template>
                            <template #cell:duration="{ row }"><Duration :minutes="Number(row.duration)" short /></template>
                            <template #cell:price="{ row }"><Money :value="money(Number(row.price))" /></template>
                            <template #actions>
                                <Menu>
                                    <MenuItem>Open</MenuItem>
                                    <MenuItem>Reschedule</MenuItem>
                                    <MenuItem danger>Cancel booking</MenuItem>
                                </Menu>
                            </template>
                        </Table>
                    </State>
                    <State name="Loading — rows shaped like the real ones">
                        <Table :columns="columns" :rows="[]" loading />
                    </State>
                    <State name="Empty">
                        <Table :columns="columns" :rows="[]">
                            <template #empty>
                                <p class="text-13 text-ink-2">No bookings in this range. Open the diary to add one.</p>
                            </template>
                        </Table>
                    </State>
                </Specimen>

                <Specimen name="Badge" note="Status at a glance. Muted on purpose — this is a diary, not an alert panel.">
                    <State name="Tones">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge tone="confirmed">Confirmed</Badge>
                            <Badge tone="pending">Awaiting deposit</Badge>
                            <Badge tone="cancelled">Cancelled</Badge>
                            <Badge tone="neutral">Completed</Badge>
                            <Badge tone="accent">Filled from waitlist</Badge>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Numbers" note="Every number in the product is mono and tabular so columns align.">
                    <State name="Money and duration">
                        <div class="space-y-1 text-14">
                            <p><Money :value="money(4500)" /> · <Duration :minutes="60" /></p>
                            <p><Money :value="money(280)" /> · <Duration :minutes="45" /></p>
                            <p><Money :value="money(123456)" /> · <Duration :minutes="90" /></p>
                            <p><Money :value="money(1000)" muted /> deposit</p>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Countdown" note="The waitlist offer and the checkout hold. Turns red under two minutes.">
                    <State name="Running">
                        <p class="text-24"><Countdown :until="soon" /></p>
                    </State>
                    <State name="Expired">
                        <p class="text-24"><Countdown until="2020-01-01T00:00:00Z" expired-label="expired" /></p>
                    </State>
                </Specimen>

                <Specimen name="Stat" note="The dashboard reading. The one that matters carries the accent rule.">
                    <State name="Row">
                        <div class="grid gap-6 sm:grid-cols-3">
                            <Stat label="Bookings this week" value="34" hint="+6 vs last week" />
                            <Stat label="Filled from waitlist" value="7" hint="£315 of revenue" emphasis />
                            <Stat label="No-show rate" value="4%" hint="Completed and no-shows" />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Tabs">
                    <State name="Default">
                        <Tabs
                            v-model="tab"
                            :tabs="[
                                { value: 'upcoming', label: 'Upcoming', count: 12 },
                                { value: 'past', label: 'Past', count: 340 },
                                { value: 'cancelled', label: 'Cancelled', count: 3 },
                            ]"
                        />
                    </State>
                </Specimen>

                <Specimen name="Pagination">
                    <State name="Default"><Pagination :page="page" :per-page="25" :total="340" @change="page = $event" /></State>
                    <State name="Empty"><Pagination :page="1" :per-page="25" :total="0" /></State>
                </Specimen>

                <Specimen name="Menu" note="Row actions live behind one affordance, never five inline links.">
                    <State name="Closed and open">
                        <Menu align="left">
                            <MenuItem>Impersonate</MenuItem>
                            <MenuItem>Extend trial</MenuItem>
                            <MenuItem>Copy preview link</MenuItem>
                            <MenuItem danger>Comp this account</MenuItem>
                        </Menu>
                    </State>
                </Specimen>

                <Specimen name="Modal" note="Focus is trapped and returns to the trigger on close.">
                    <State name="Trigger"><Button variant="secondary" @click="modal = true">Open modal</Button></State>
                </Specimen>

                <Specimen name="Slide over">
                    <State name="Trigger"><Button variant="secondary" @click="slide = true">Open slide over</Button></State>
                </Specimen>

                <Specimen name="Confirm dialog" note="Names the exact consequence. Never 'Are you sure?'">
                    <State name="Trigger"><Button variant="danger" @click="confirm = true">Cancel booking</Button></State>
                </Specimen>

                <Specimen name="Toast" note="A receipt, never the only place an error appears.">
                    <State name="Tones">
                        <div class="flex flex-wrap gap-2">
                            <Button variant="secondary" @click="toast('Booking saved.')">Neutral</Button>
                            <Button variant="secondary" @click="toast('Deposit refunded.', { tone: 'success' })">Success</Button>
                            <Button variant="secondary" @click="toast('That time was just taken.', { tone: 'danger' })">Danger</Button>
                            <Button
                                variant="secondary"
                                @click="toast('Booking cancelled.', { action: { label: 'Undo', run: () => toast('Restored.') } })"
                                >With an action</Button
                            >
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Callout" note="Inline, where the thing it is about lives. Not a banner following her around.">
                    <State name="Tones">
                        <div class="max-w-measure space-y-3">
                            <Callout title="Stripe isn't connected">
                                You can take bookings without deposits until you connect.
                                <template #action><Button variant="secondary" @click="() => {}">Connect Stripe</Button></template>
                            </Callout>
                            <Callout tone="accent" title="Trial ends in 3 days">
                                Clients can still book online after it ends.
                            </Callout>
                            <Callout tone="danger" title="Billing is out of date">
                                The diary is read-only until a card is added.
                            </Callout>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Empty state" note="One sentence and one action.">
                    <State name="With an action">
                        <EmptyState
                            title="No services yet"
                            description="Add what you offer and how long it takes, and your booking page comes to life."
                            action-label="Add your first service"
                        />
                    </State>
                    <State name="Without"><EmptyState title="Nothing on the diary today." /></State>
                </Specimen>

                <Specimen name="Skeleton" note="Shaped like the content it replaces. Never a generic bar, never a spinner.">
                    <State name="Shapes">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <Skeleton shape="text" :lines="3" />
                            <Skeleton shape="heading" />
                            <Skeleton shape="card" />
                            <Skeleton shape="stat" />
                        </div>
                    </State>
                    <State name="Rows"><Skeleton shape="row" :lines="3" /></State>
                </Specimen>

                <Specimen name="Spinner" note="Only inside a control that is already committed to an action.">
                    <State name="Default"><Spinner /></State>
                </Specimen>

                <Specimen name="Card">
                    <State name="With a title and actions">
                        <Card title="Today">
                            <template #actions><Button variant="ghost">Open diary</Button></template>
                            <p class="text-13 text-ink-2">Three appointments, one awaiting a deposit.</p>
                        </Card>
                    </State>
                </Specimen>

                <Specimen name="Page header">
                    <State name="With an action">
                        <PageHeader title="Bookings" description="Filter by status and date.">
                            <Button variant="secondary">Export</Button>
                            <Button>New booking</Button>
                        </PageHeader>
                    </State>
                </Specimen>

                <Specimen name="Label" note="The 11px mono instrument label. Most of what stops this being a grey admin panel.">
                    <State name="Default"><Label>Deposit taken</Label></State>
                    <State name="Required"><Label required>Business name</Label></State>
                </Specimen>

                <Specimen name="Key hint" note="The app teaches its own shortcuts in passing.">
                    <State name="Examples">
                        <div class="flex items-center gap-4 text-13 text-ink-2">
                            <span>Search <KeyHint :keys="['⌘', 'K']" /></span>
                            <span>New booking <KeyHint :keys="['n']" /></span>
                            <span>Today <KeyHint :keys="['t']" /></span>
                        </div>
                    </State>
                </Specimen>
            </main>
        </div>

        <Modal :show="modal" title="Move this booking?" description="Priya Raman, full groom." @close="modal = false">
            <p class="text-13 text-ink-2">Pick a new time from the slots still free that day.</p>
            <template #footer>
                <Button variant="ghost" @click="modal = false">Keep it</Button>
                <Button @click="modal = false">Choose a time</Button>
            </template>
        </Modal>

        <SlideOver :show="slide" title="New booking" description="Tuesday 10 March" @close="slide = false">
            <div class="space-y-4">
                <TextInput v-model="empty" label="Client name" />
                <TextInput v-model="empty" label="Email" type="email" />
                <Select
                    v-model="choice"
                    label="Service"
                    :options="[
                        { value: 'full-groom', label: 'Full groom' },
                        { value: 'bath', label: 'Bath and blow dry' },
                    ]"
                />
            </div>
            <template #footer>
                <Button variant="ghost" @click="slide = false">Cancel</Button>
                <Button @click="slide = false">Save booking</Button>
            </template>
        </SlideOver>

        <ConfirmDialog
            :show="confirm"
            title="Cancel this booking?"
            confirm-label="Cancel and refund £10"
            cancel-label="Keep it"
            @close="confirm = false"
            @confirm="confirm = false"
        >
            Priya Raman's full groom on 10 March at 09:00. She is outside the 48-hour window, so her £10 deposit is
            refunded and 3 people on the waitlist are texted.
        </ConfirmDialog>

        <Toaster />
    </div>
</template>
