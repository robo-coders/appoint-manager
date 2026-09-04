<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';

import Specimen from './Specimen.vue';
import State from './State.vue';

import AppLogo from '@/Components/AppLogo.vue';
import CommandPalette from '@/Components/ui/CommandPalette.vue';
import Badge from '@/Components/ui/Badge.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import Countdown from '@/Components/ui/Countdown.vue';
import DayButton from '@/Components/ui/DayButton.vue';
import FileDrop from '@/Components/ui/FileDrop.vue';
import GapButton from '@/Components/ui/GapButton.vue';
import NavRail from '@/Components/ui/NavRail.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import RailUserMenu from '@/Components/ui/RailUserMenu.vue';
import SaveState from '@/Components/ui/SaveState.vue';
import SlotButton from '@/Components/ui/SlotButton.vue';
import StaffColourField from '@/Components/ui/StaffColourField.vue';
import TimeBlock from '@/Components/ui/TimeBlock.vue';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Card from '@/Components/ui/Card.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import DateTime from '@/Components/ui/DateTime.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import Field from '@/Components/ui/Field.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import KeyHint from '@/Components/ui/KeyHint.vue';
import Label from '@/Components/ui/Label.vue';
import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import Modal from '@/Components/ui/Modal.vue';
import Money from '@/Components/ui/Money.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import Skeleton from '@/Components/ui/Skeleton.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Spinner from '@/Components/ui/Spinner.vue';
import Stat from '@/Components/ui/Stat.vue';
import SwatchGroup from '@/Components/ui/SwatchGroup.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Toaster from '@/Components/ui/Toaster.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import UserMenu from '@/Components/ui/UserMenu.vue';
import { toast } from '@/lib/toast';

/*
 * Every component, every state. This page is the phase deliverable: if a state
 * is not on here it has not been designed, and if it looks wrong here it is
 * wrong everywhere, because there is exactly one implementation.
 *
 * The density switch sets `data-density` on this page's root, which is the only
 * mechanism any component has for sizing. No component takes a size prop.
 */
const density = ref<'compact' | 'roomy' | 'console'>('compact');

const text = ref('Willow Street Grooming');
const empty = ref('');
const area = ref('Nervous around clippers. Muzzle in the bag.');
const choice = ref('full-groom');
const zone = ref('Europe/London');
const checked = ref(true);
const toggled = ref(true);
const tab = ref('upcoming');

// ---- state for the components added in phases 5 to 7 --------------------
const slotTime = ref('09:45');
const pickedDay = ref('10');
const staffColour = ref('#7B3448'); // design-tokens-ignore: per-user data, exactly as the real form stores it
const savedAt = ref<number | null>(Date.now() - 5_000);
const railCollapsed = ref(false);

/*
 * An hour out, so the countdown draws its `Nh MMm` form as well as `mm:ss`.
 * `Date.now()` at setup rather than a fixed instant: a gallery whose timer has
 * already expired is a gallery of one state.
 */
const offerExpiry = computed(() => new Date(Date.now() + 62 * 60 * 1000).toISOString());

const railLinks = [
    { href: '#navrail', label: 'Diary', glyph: 'Di' },
    { href: '#nothing', label: 'Bookings', glyph: 'Bk', count: 12 },
    { href: '#nothing', label: 'Customers', glyph: 'Cu', count: 348 },
    { href: '#nothing', label: 'Waitlist', glyph: 'Wl', count: 3 },
    { href: '#nothing', label: 'Services', glyph: 'Sv', count: 9 },
    { href: '#nothing', label: 'Staff', glyph: 'St', count: 4 },
    { href: '#nothing', label: 'Settings', glyph: 'Se' },
];

const modal = ref(false);
const slide = ref(false);
const confirm = ref(false);
const palette = ref(false);
const swatch = ref<string | null>('navy');
const swatchEmpty = ref<string | null>(null);
const loadingTable = ref(false);
const emptyTable = ref(false);

const services = [
    { value: 'full-groom', label: 'Full groom' },
    { value: 'puppy-trim', label: 'Puppy trim' },
    { value: 'nail-trim', label: 'Nail trim' },
];

const zones = [
    { value: 'Europe/London', label: 'Europe/London' },
    { value: 'Europe/Dublin', label: 'Europe/Dublin' },
    { value: 'Europe/Lisbon', label: 'Europe/Lisbon' },
];

/*
 * The bookings table, which is the shape every other table in the product is a
 * subset of. Column widths are token names — `when`, `staff`, `status`,
 * `amount` — never a pixel value typed into a template.
 */
const columns: Column[] = [
    { key: 'when', label: 'When', width: 'when', sortable: true, numeric: true },
    { key: 'customer', label: 'Customer', sortable: true },
    { key: 'service', label: 'Service', secondary: true },
    { key: 'staff', label: 'Staff', width: 'staff' },
    { key: 'status', label: 'Status', width: 'status' },
    { key: 'amount', label: 'Amount', width: 'amount', align: 'right', numeric: true, sortable: true },
];

const rows = [
    { id: 1, when: '10 Mar 09:00', customer: 'Nina Hart', service: 'Full groom', staff: 'Ana', status: 'confirmed', amount: '£45.00' },
    { id: 2, when: '10 Mar 10:30', customer: 'Sam Okafor', service: 'Nail trim', staff: 'Marek', status: 'pending', amount: '£18.00' },
    { id: 3, when: '10 Mar 12:00', customer: 'Priya Shah', service: 'Puppy trim', staff: 'Ana', status: 'confirmed', amount: '£38.00' },
    { id: 4, when: '11 Mar 09:30', customer: 'Tom Beckett', service: 'Full groom', staff: 'Ana', status: 'cancelled', amount: '£45.00' },
];

const tableRows = computed(() => (emptyTable.value ? [] : rows));

const badgeTone = (status: string) =>
    status === 'confirmed' ? 'confirmed' : status === 'cancelled' ? 'cancelled' : 'pending';

const badgeLabel = (status: string) =>
    status === 'confirmed' ? 'Confirmed' : status === 'cancelled' ? 'Cancelled' : 'Awaiting deposit';

const SECTIONS = [
    'Button', 'TextInput', 'Select', 'Combobox', 'Textarea', 'Checkbox', 'Toggle',
    'Table', 'Badge', 'Modal', 'SlideOver', 'ConfirmDialog', 'Toast', 'EmptyState',
    'Skeleton', 'Money', 'DateTime', 'PageHeader', 'Tabs', 'Menu', 'UserMenu',
    'CommandPalette', 'AppLogo', 'Card', 'Stat', 'Callout', 'Label and Field',
    'KeyHint', 'Spinner',
];

const anchor = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
</script>

<template>
    <div :data-density="density" class="min-h-screen bg-paper text-ink">
        <Head title="Components" />
        <Toaster />
        <CommandPalette :show="palette" @close="palette = false" />

        <header class="sticky top-0 z-20 border-b border-b-rule bg-paper/95 px-6 py-3">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <AppLogo :size="18" />
                    <span class="caption">component gallery</span>
                </div>
                <div class="flex items-center gap-2">
                    <Label>Density</Label>
                    <div class="flex items-center gap-1">
                        <Button
                            v-for="option in (['compact', 'roomy', 'console'] as const)"
                            :key="option"
                            :variant="density === option ? 'primary' : 'secondary'"
                            @click="density = option"
                        >
                            {{ option }}
                        </Button>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto max-w-6xl px-6 py-8">
            <PageHeader
                title="Components"
                description="Every component the product owns, in every state it can be in. One implementation each — a screen that hand-rolls one of these fails check:components."
            />

            <nav aria-label="Components" class="mb-8 flex flex-wrap gap-x-4 gap-y-1">
                <a
                    v-for="name in SECTIONS"
                    :key="name"
                    :href="`#${anchor(name)}`"
                    class="text-13 text-ink-2 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:text-ink"
                >
                    {{ name }}
                </a>
            </nav>

            <div class="space-y-12">
                <!-- ─────────────────────────────── controls ─────────────── -->

                <Specimen name="Button" note="Five variants. Primary is ink on paper — at most one per screen. `brand` is primary repainted in the tenant's colour and belongs to the public booking page alone. Loading keeps full contrast because it is working, not unavailable; only a genuinely disabled control fades.">
                    <State name="Variants">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button>Save changes</Button>
                            <Button variant="secondary">Cancel</Button>
                            <Button variant="ghost">Skip</Button>
                            <Button variant="danger">Delete</Button>
                        </div>
                    </State>
                    <State name="variant=&quot;brand&quot; — ink until a tenant picks a colour">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button variant="brand">Confirm booking</Button>
                            <span style="--brand: var(--brand-forest)"><Button variant="brand">Confirm booking</Button></span>
                            <span style="--brand: var(--brand-plum)"><Button variant="brand">Confirm booking</Button></span>
                        </div>
                    </State>
                    <State name="Hover (pointer over each)">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button>Hover me</Button>
                            <Button variant="secondary">Hover me</Button>
                            <Button variant="ghost">Hover me</Button>
                        </div>
                    </State>
                    <State name="Focus — Tab to it. The ring is the only one in the product.">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button>Tab to me</Button>
                            <Button variant="secondary">Then to me</Button>
                        </div>
                    </State>
                    <State name="Disabled">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button disabled>Save changes</Button>
                            <Button variant="secondary" disabled>Cancel</Button>
                        </div>
                    </State>
                    <State name="Loading">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button loading>Save changes</Button>
                            <Button variant="secondary" loading>Checking</Button>
                        </div>
                    </State>
                    <State name="Block, and as a link">
                        <div class="max-w-booking space-y-2">
                            <Button block>Confirm booking</Button>
                            <Button href="#button" variant="secondary" block>An anchor, not a button</Button>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="TextInput" note="Label, control, hint or error. The label is structural rather than a rule people have to remember. Numeric types get mono tabular figures without being asked.">
                    <div class="grid max-w-measure gap-4 md:grid-cols-2">
                        <State name="Default"><TextInput v-model="text" label="Business name" /></State>
                        <State name="With hint"><TextInput v-model="empty" label="Postcode" hint="We only use this for directions." /></State>
                        <State name="Error"><TextInput v-model="empty" label="Email" type="email" error="Enter an email address we can reach you on." /></State>
                        <State name="Disabled"><TextInput v-model="text" label="Slug" disabled /></State>
                        <State name="Read only"><TextInput v-model="text" label="Reference" readonly /></State>
                        <State name="Required"><TextInput v-model="empty" label="Phone" required /></State>
                        <State name="Prefix and suffix (mono)"><TextInput v-model="empty" label="Deposit" type="number" prefix="£" /></State>
                        <State name="Suffix"><TextInput v-model="empty" label="Duration" type="number" suffix="min" /></State>
                    </div>
                </Specimen>

                <Specimen name="Select" note="A native select in the product's own clothes. Used when the list is short and known.">
                    <div class="grid max-w-measure gap-4 md:grid-cols-2">
                        <State name="Default"><Select v-model="choice" label="Service" :options="services" /></State>
                        <State name="Error"><Select v-model="choice" label="Service" :options="services" error="Pick a service." /></State>
                        <State name="Disabled"><Select v-model="choice" label="Service" :options="services" disabled /></State>
                    </div>
                </Specimen>

                <Specimen name="Combobox" note="Type to filter. For a list too long to scroll — timezones, customers. Arrow keys move, Enter selects, Escape closes.">
                    <div class="grid max-w-measure gap-4 md:grid-cols-2">
                        <State name="Default"><Combobox v-model="zone" label="Timezone" :options="zones" /></State>
                        <State name="Error"><Combobox v-model="zone" label="Timezone" :options="zones" error="Not a timezone we know." /></State>
                    </div>
                </Specimen>

                <Specimen name="Textarea">
                    <div class="grid max-w-measure gap-4 md:grid-cols-2">
                        <State name="Default"><Textarea v-model="area" label="Notes" /></State>
                        <State name="Error"><Textarea v-model="empty" label="Notes" error="Say something or leave it blank." /></State>
                        <State name="Disabled"><Textarea v-model="area" label="Notes" disabled /></State>
                    </div>
                </Specimen>

                <Specimen name="Checkbox" note="Square, 6px radius, ink when checked. Selected states are ink everywhere in this system.">
                    <div class="space-y-3">
                        <State name="Checked"><Checkbox v-model="checked" label="Send a reminder the day before" /></State>
                        <State name="Unchecked"><Checkbox :model-value="false" label="Take a deposit" /></State>
                        <State name="With hint"><Checkbox v-model="checked" label="Show on the booking page" hint="Customers can only book services that are shown." /></State>
                        <State name="Disabled"><Checkbox :model-value="true" label="Locked by your plan" disabled /></State>
                    </div>
                </Specimen>

                <Specimen name="Toggle" note="For a setting that takes effect immediately. If it needs a Save button, it is a Checkbox.">
                    <div class="space-y-3">
                        <State name="On"><Toggle v-model="toggled" label="Booking page is live" /></State>
                        <State name="Off"><Toggle :model-value="false" label="Send SMS reminders" /></State>
                        <State name="Disabled"><Toggle :model-value="true" label="Deposits" disabled /></State>
                    </div>
                </Specimen>

                <!-- ─────────────────────────────── data ─────────────────── -->

                <Specimen name="Table" note="Sortable, sticky header, hairline rows, no zebra. Numbers right and mono. Row actions in one menu, not five inline links. The loading state is one bar per column at that column's width — not three bars and a gap.">
                    <State name="Controls">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button variant="secondary" @click="loadingTable = !loadingTable">
                                {{ loadingTable ? 'Show rows' : 'Show loading' }}
                            </Button>
                            <Button variant="secondary" @click="emptyTable = !emptyTable">
                                {{ emptyTable ? 'Show rows' : 'Show empty' }}
                            </Button>
                        </div>
                    </State>
                    <State name="Populated, loading and empty — toggle above">
                        <Table
                            :columns="columns"
                            :rows="tableRows"
                            :loading="loadingTable"
                            label="Bookings"
                            empty-title="No bookings yet"
                            empty-description="When someone books online they will appear here."
                        >
                            <template #cell:status="{ row }">
                                <Badge :tone="badgeTone(String(row.status))">{{ badgeLabel(String(row.status)) }}</Badge>
                            </template>
                            <template #actions>
                                <MenuItem>Open</MenuItem>
                                <MenuItem>Reschedule</MenuItem>
                                <MenuItem danger>Cancel</MenuItem>
                            </template>
                        </Table>
                    </State>
                    <State name="Three columns — the skeleton follows the columns it is given">
                        <Table
                            :columns="[
                                { key: 'when', label: 'When', width: 'time', numeric: true },
                                { key: 'customer', label: 'Customer' },
                                { key: 'amount', label: 'Amount', width: 'amount', align: 'right', numeric: true },
                            ]"
                            :rows="[]"
                            loading
                            :loading-rows="3"
                            label="Three column example"
                        />
                    </State>
                </Specimen>

                <Specimen name="Badge" note="Status carries its own label always — meaning is never in the colour. Fixed height so a column of them sits on one baseline. Only a cancellation earns colour.">
                    <State name="Tones">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge tone="confirmed">Confirmed</Badge>
                            <Badge tone="pending">Awaiting deposit</Badge>
                            <Badge tone="cancelled">Cancelled</Badge>
                            <Badge tone="neutral">Draft</Badge>
                            <Badge tone="accent">First available</Badge>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Money" note="Mono, tabular, so a column of prices aligns on the decimal.">
                    <State name="Values">
                        <div class="flex flex-wrap items-center gap-6">
                            <Money :value="{ amount: 4500, formatted: '£45.00', currency: 'GBP' }" />
                            <Money :value="{ amount: 0, formatted: '£0.00', currency: 'GBP' }" />
                            <Money value="£1,234.56" muted />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="DateTime" note="One place decides how a date reads, so the diary and the booking page never disagree.">
                    <State name="Formats">
                        <div class="flex flex-wrap items-center gap-6">
                            <DateTime value="2026-03-10T09:00:00Z" />
                            <DateTime value="2026-03-10T09:00:00Z" time-only />
                            <DateTime value="2026-03-10T09:00:00Z" date-only />
                            <DateTime value="2026-03-10T09:00:00Z" relative />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Stat" note="A figure with a rule beside it, not a card with a number in it. Containment is spent once.">
                    <State name="Default and emphasis">
                        <div class="grid gap-6 md:grid-cols-3">
                            <Stat label="Booked this week" value="34" hint="6 more than last week" />
                            <Stat label="No-shows" value="2" hint="Both rebooked" />
                            <Stat label="Taken" value="£1,248" emphasis />
                        </div>
                    </State>
                </Specimen>

                <!-- ─────────────────────────────── overlays ─────────────── -->

                <Specimen name="Modal" note="Traps focus, closes on Escape and on a click outside, and puts focus back on whatever opened it.">
                    <State name="Open it and press Tab, then Escape">
                        <Button variant="secondary" @click="modal = true">Open modal</Button>
                        <Modal :show="modal" title="Cancel this booking" @close="modal = false">
                            <p class="text-14 text-ink-2">Nina will be told, and the slot goes back on the diary.</p>
                            <template #footer>
                                <Button variant="secondary" @click="modal = false">Keep it</Button>
                                <Button variant="danger" @click="modal = false">Cancel booking</Button>
                            </template>
                        </Modal>
                    </State>
                </Specimen>

                <Specimen name="SlideOver" note="The same behaviour as Modal, from the right, for editing something without leaving the list.">
                    <State name="Open it and press Tab, then Escape">
                        <Button variant="secondary" @click="slide = true">Open slide-over</Button>
                        <SlideOver :show="slide" title="Edit service" @close="slide = false">
                            <div class="space-y-4">
                                <TextInput v-model="text" label="Name" />
                                <TextInput v-model="empty" label="Duration" type="number" suffix="min" />
                            </div>
                            <template #footer>
                                <Button variant="secondary" @click="slide = false">Cancel</Button>
                                <Button @click="slide = false">Save</Button>
                            </template>
                        </SlideOver>
                    </State>
                </Specimen>

                <Specimen name="ConfirmDialog" note="For the one action that cannot be undone. The destructive button is never the one focus lands on first.">
                    <State name="Open">
                        <Button variant="danger" @click="confirm = true">Delete service</Button>
                        <ConfirmDialog
                            :show="confirm"
                            title="Delete this service"
                            body="Bookings already taken keep their price. This cannot be undone."
                            confirm-label="Delete"
                            @close="confirm = false"
                            @confirm="confirm = false"
                        />
                    </State>
                </Specimen>

                <Specimen name="Toast" note="Confirms something that already happened, and carries the undo if there is one. Never the only place an error appears — an error belongs under the field that caused it.">
                    <State name="Tones">
                        <div class="flex flex-wrap gap-3">
                            <Button variant="secondary" @click="toast('Booking confirmed.')">Neutral</Button>
                            <Button variant="secondary" @click="toast('Service saved.', { tone: 'success' })">Success</Button>
                            <Button variant="secondary" @click="toast('Could not reach Stripe.', { tone: 'danger' })">Danger</Button>
                            <Button
                                variant="secondary"
                                @click="toast('Booking cancelled.', { action: { label: 'Undo', run: () => toast('Restored.') } })"
                            >
                                With an action
                            </Button>
                        </div>
                    </State>
                </Specimen>

                <!-- ─────────────────────────────── states ───────────────── -->

                <Specimen name="EmptyState" note="One sentence and one action. Never a shrug.">
                    <State name="With an action">
                        <EmptyState
                            title="No services yet"
                            description="Add the things you actually do. Customers can only book what is listed here."
                        >
                            <Button>Add a service</Button>
                        </EmptyState>
                    </State>
                    <State name="Without">
                        <EmptyState title="Nothing on the diary today" description="Enjoy it." />
                    </State>
                </Specimen>

                <Specimen name="Skeleton" note="Shaped like the content it replaces. Never a spinner, and never a generic bar. Appears only after 200ms so a fast action does not flash.">
                    <div class="grid gap-6 md:grid-cols-2">
                        <State name="Text"><Skeleton shape="text" :lines="3" /></State>
                        <State name="Heading"><Skeleton shape="heading" /></State>
                        <State name="Card"><Skeleton shape="card" /></State>
                        <State name="Stat"><Skeleton shape="stat" /></State>
                        <State name="Block"><Skeleton shape="block" width="w-full" /></State>
                        <State name="Row — one bar per column">
                            <Skeleton
                                shape="row"
                                :lines="4"
                                :columns="[
                                    { width: 'w-col-time' },
                                    {},
                                    { width: 'w-col-staff' },
                                    { width: 'w-col-amount', align: 'right' },
                                ]"
                            />
                        </State>
                    </div>
                </Specimen>

                <Specimen name="Spinner" note="Exists for exactly one job: inside a Button that is working. Everywhere else a loading state is a Skeleton.">
                    <State name="In place, and in a button">
                        <div class="flex items-center gap-6">
                            <Spinner />
                            <Button loading>Saving</Button>
                        </div>
                    </State>
                </Specimen>

                <!-- ─────────────────────────────── chrome ───────────────── -->

                <Specimen name="PageHeader" note="Title, one line of context, and the screen's one primary action.">
                    <State name="With an action">
                        <PageHeader title="Services" description="What you do, how long it takes, what it costs.">
                            <Button>Add a service</Button>
                        </PageHeader>
                    </State>
                    <State name="Without">
                        <PageHeader title="Overview" description="Hello Nina." />
                    </State>
                </Specimen>

                <Specimen name="Tabs" note="Arrows move between tabs, Home and End jump to the ends, and only the selected tab is in the tab order.">
                    <State name="Selected, with counts">
                        <Tabs
                            v-model="tab"
                            label="Bookings"
                            :tabs="[
                                { value: 'upcoming', label: 'Upcoming', count: 12 },
                                { value: 'past', label: 'Past', count: 148 },
                                { value: 'cancelled', label: 'Cancelled', count: 3 },
                            ]"
                        />
                        <p class="mt-3 text-13 text-ink-2">Selected: {{ tab }}</p>
                    </State>
                </Specimen>

                <Specimen name="Menu" note="One affordance per row. Enter or Down opens it, arrows move, Escape closes and puts focus back on the trigger.">
                    <State name="Closed and open">
                        <div class="flex items-center gap-6">
                            <Menu label="Row actions">
                                <MenuItem>Open</MenuItem>
                                <MenuItem>Reschedule</MenuItem>
                                <MenuItem disabled>Refund (outside the window)</MenuItem>
                                <MenuItem danger>Cancel</MenuItem>
                            </Menu>
                            <span class="text-13 text-ink-2">← press Enter, then arrow down</span>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="UserMenu" note="The signed-in person and the two things they do from the top bar. Log out is a POST, never a link a prefetcher can fire.">
                    <State name="Default">
                        <UserMenu name="Nina Hart" detail="Willow Street Grooming" profile-href="#usermenu" logout-href="#usermenu" />
                    </State>
                    <State name="While a super admin is impersonating">
                        <UserMenu
                            name="Nina Hart"
                            detail="Willow Street Grooming"
                            profile-href="#usermenu"
                            logout-href="#usermenu"
                            impersonating
                            stop-impersonating-href="#usermenu"
                        />
                    </State>
                </Specimen>

                <Specimen name="CommandPalette" note="Search over customers, and the keyboard route to every screen.">
                    <State name="Open it">
                        <Button variant="secondary" @click="palette = true">Open palette</Button>
                    </State>
                </Specimen>

                <Specimen name="AppLogo" note="Four files, one component: the lockup and the mark, each in a light and a reversed colourway. `tone=&quot;brand&quot;` is gone with the hand-drawn SVG it recoloured — artwork loaded as a file cannot inherit currentColor, and the one surface a tenant's colour was ever for is the public booking page, which wears the salon's initial and not our logo.">
                    <State name="Lockup, mark, and at favicon size">
                        <div class="flex items-center gap-8">
                            <AppLogo :size="24" />
                            <AppLogo :size="24" variant="mark" />
                            <AppLogo :size="16" variant="mark" />
                        </div>
                    </State>
                    <State name="reversed — the same two, for a dark ground">
                        <div class="flex items-center gap-8 rounded bg-ink p-4">
                            <AppLogo :size="24" reversed />
                            <AppLogo :size="24" variant="mark" reversed />
                            <AppLogo :size="16" variant="mark" reversed />
                        </div>
                    </State>
                </Specimen>

                <Specimen name="SwatchGroup" note="Single-select colour, six presets, no free hex field. A radio group: arrow keys move and select, one tab stop for the whole group. Selection is carried by a tick and by the name going to ink — never by colour alone.">
                    <State name="Six presets, one chosen">
                        <SwatchGroup v-model="swatch" :options="['forest', 'plum', 'navy', 'ochre', 'slate', 'clay']" label="Booking page colour" />
                    </State>
                    <State name="Nothing chosen — the first swatch still takes the tab stop">
                        <SwatchGroup v-model="swatchEmpty" :options="['forest', 'plum', 'navy', 'ochre', 'slate', 'clay']" label="Booking page colour, unset" />
                    </State>
                </Specimen>

                <Specimen name="Card" note="A container for one thing. No nested cards: if a card contains a card, remove one.">
                    <State name="With a title and an action">
                        <Card title="Today">
                            <template #actions><Button variant="ghost">Open diary</Button></template>
                            <p class="text-14 text-ink-2">Six bookings, one waiting on a deposit.</p>
                        </Card>
                    </State>
                </Specimen>

                <Specimen name="Callout" note="A standing condition, not an event. An event is a Toast.">
                    <State name="Tones">
                        <div class="space-y-3">
                            <Callout title="Trial ends in 6 days">Add a card to keep writing to the diary.</Callout>
                            <Callout tone="danger" title="Billing is out of date">
                                The diary is read-only. Clients can still book online.
                            </Callout>
                        </div>
                    </State>
                </Specimen>

                <Specimen name="Label and Field" note="Field is label + control + hint or error, so &quot;every input has a visible label&quot; is structural. The required marker is ink: a form with six required fields would otherwise spend the accent six times.">
                    <div class="grid max-w-measure gap-4 md:grid-cols-2">
                        <State name="Label">
                            <Label>Business name</Label>
                            <Label required>Email</Label>
                        </State>
                        <State name="Field wrapping something custom">
                            <Field input-id="custom" label="Opening hours" hint="Tuesday to Saturday.">
                                <div id="custom" class="rounded border border-rule bg-paper-sunk px-pad-x py-2 text-13 text-ink-2">
                                    A control the library does not own yet
                                </div>
                            </Field>
                        </State>
                        <State name="FieldError on its own">
                            <FieldError id="standalone" message="That slot was taken while you were deciding." />
                        </State>
                    </div>
                </Specimen>


                <Specimen
                    name="NavRail"
                    note="The operator shell's rail, at both widths it has. The 56px version is why this entry exists: deriving its glyphs from first letters gave Services, Staff and Settings all `S`, and that was invisible until the two were drawn side by side."
                >
                    <State name="148px, and the 56px icon rail">
                        <div class="flex gap-8">
                            <div class="relative h-96 w-rail overflow-hidden rounded border border-rule">
                                <NavRail
                                    :links="railLinks"
                                    :is-current="(href) => href === '#navrail'"
                                    home-href="#navrail"
                                    user-name="Rosa Adeyemi"
                                    profile-href="#navrail"
                                    billing-href="#navrail"
                                    logout-href="#navrail"
                                    class="!absolute"
                                />
                            </div>
                            <div class="relative h-96 w-rail-collapsed overflow-hidden rounded border border-rule">
                                <NavRail
                                    :links="railLinks"
                                    :is-current="(href) => href === '#navrail'"
                                    home-href="#navrail"
                                    user-name="Rosa Adeyemi"
                                    profile-href="#navrail"
                                    logout-href="#navrail"
                                    collapsed
                                    class="!absolute"
                                />
                            </div>
                        </div>
                        <p class="caption mt-3 max-w-measure">
                            The icons are the 56px rail's only content, and they are decorative in the
                            markup: the accessible name and the tooltip both come from the label. The
                            148px rail stays text — the mockup draws it that way, and an icon beside a
                            word it duplicates is decoration with a width.
                        </p>
                    </State>
                </Specimen>

                <Specimen
                    name="RailUserMenu"
                    note="Pinned to the bottom of the rail and opening upward, so the chevron points up when closed and down when open — direction says where the menu will go, not what state it is in. While impersonating it becomes a danger-bordered block, permanently, in the one place a borrowed session is always visible."
                >
                    <div class="grid gap-6 md:grid-cols-3">
                        <State name="Closed">
                            <div class="w-rail rounded border border-rule bg-paper-sunk">
                                <RailUserMenu name="Rosa Adeyemi" profile-href="#" billing-href="#" logout-href="#" />
                            </div>
                        </State>
                        <State name="Icon rail">
                            <div class="w-rail-collapsed rounded border border-rule bg-paper-sunk">
                                <RailUserMenu name="Rosa Adeyemi" profile-href="#" logout-href="#" collapsed />
                            </div>
                        </State>
                        <State name="Impersonating">
                            <div class="w-rail rounded border border-rule bg-paper-sunk">
                                <RailUserMenu
                                    name="Rosa Adeyemi"
                                    profile-href="#"
                                    logout-href="#"
                                    impersonating
                                    impersonated-tenant="Willow Street Grooming"
                                    stop-impersonating-href="#"
                                />
                            </div>
                        </State>
                    </div>
                </Specimen>

                <Specimen
                    name="TimelineRow"
                    note="One row of a day, and the dashboard's timeline and the diary's 375px agenda are both built from it. Five tones: default, past (muted, no detail), current (2px ink border and an extra line), freed (the only coloured row) and gap (open time, drawn as space)."
                >
                    <State name="All five tones, in the order a day produces them">
                        <ul class="rounded border border-rule bg-paper">
                            <TimelineRow time="09:00" tone="past" meta="Ana" amount="£45.00" detail="Dropped on a past row">
                                Bramble — full groom
                            </TimelineRow>
                            <TimelineRow time="10:30" meta="Marek" amount="£18.00">Suki — nail trim</TimelineRow>
                            <TimelineRow
                                time="12:00"
                                tone="current"
                                meta="Ana"
                                amount="£38.00"
                                detail="In the chair 14 min · deposit paid · first visit, nervous with clippers"
                            >
                                Pepper — puppy trim
                            </TimelineRow>
                            <TimelineRow time="15:30" tone="freed">
                                Marlow cancelled, <span class="numeral">60</span> min open
                                <template #action>
                                    <Button variant="accent" class="shrink-0">Offer to 3 waiting</Button>
                                </template>
                            </TimelineRow>
                            <TimelineRow time="16:30" tone="gap" interactive aria-label="90 minutes free. Book it.">
                                <span class="numeral">90 min free</span>
                            </TimelineRow>
                        </ul>
                    </State>
                </Specimen>

                <Specimen
                    name="TimeBlock and GapButton"
                    note="The diary grid's two elements. A block is flat — paper, a hairline, and a 2px left border carrying the status; nothing is filled. A gap occupies the minutes it represents, so a 90-minute hole is visibly three times a 30-minute one, and pressing it books into itself."
                >
                    <State name="Blocks, one per status">
                        <div class="flex flex-wrap gap-3">
                            <div class="h-16 w-48"><TimeBlock time="09:00" title="Bramble" detail="Full groom" /></div>
                            <div class="h-16 w-48"><TimeBlock time="10:30" title="Suki" detail="Nail trim" tone="pending" /></div>
                            <div class="h-16 w-48"><TimeBlock time="12:00" title="Pepper" detail="Puppy trim" tone="current" /></div>
                            <div class="h-16 w-48"><TimeBlock time="15:30" title="Marlow" detail="Full groom" tone="freed" /></div>
                            <div class="h-16 w-48"><TimeBlock time="09:15" title="Otto" detail="Bath" past /></div>
                            <div class="h-16 w-48">
                                <TimeBlock time="13:30" title="Juno" detail="Full groom" :overrun-minutes="30" overlapping />
                            </div>
                        </div>
                    </State>
                    <State name="Gaps, at the heights they really are">
                        <div class="flex w-48 flex-col gap-1 rounded border border-rule bg-white p-1">
                            <div class="h-6"><GapButton :minutes="15" :ariaLabel="'15 minutes free'" /></div>
                            <div class="h-12"><GapButton :minutes="30" :ariaLabel="'30 minutes free'" /></div>
                            <div class="h-24"><GapButton :minutes="60" :ariaLabel="'60 minutes free'" /></div>
                        </div>
                    </State>
                </Specimen>

                <Specimen
                    name="SlotButton and DayButton"
                    note="The public booking page's fallback picker. Unavailable entries keep their place: removing them leaves a grid of three, which cannot say whether the salon is busy or shut, and a day with nothing left leaves an empty box that reads as broken. They carry `aria-disabled`, not `disabled`, so they stay reachable, and the meaning is in the accessible name rather than in the strike-through."
                >
                    <State name="Times">
                        <div class="grid max-w-md grid-cols-3 gap-2">
                            <SlotButton time="09:00" :selected="slotTime === '09:00'" @pick="slotTime = '09:00'" />
                            <SlotButton time="09:15" :available="false" />
                            <SlotButton time="09:45" :selected="slotTime === '09:45'" @pick="slotTime = '09:45'" />
                            <SlotButton time="10:30" :selected="slotTime === '10:30'" @pick="slotTime = '10:30'" />
                            <SlotButton time="11:15" :selected="slotTime === '11:15'" @pick="slotTime = '11:15'" />
                            <SlotButton time="11:30" :available="false" />
                        </div>
                    </State>
                    <State name="Days">
                        <div class="grid max-w-md grid-cols-7 gap-1">
                            <DayButton weekday="Mon" day-of-month="9" full-label="Monday 9 March" :selected="pickedDay === '9'" @pick="pickedDay = '9'" />
                            <DayButton weekday="Tue" day-of-month="10" full-label="Tuesday 10 March" :selected="pickedDay === '10'" @pick="pickedDay = '10'" />
                            <DayButton weekday="Wed" day-of-month="11" full-label="Wednesday 11 March" :selected="pickedDay === '11'" @pick="pickedDay = '11'" />
                            <DayButton weekday="Thu" day-of-month="12" full-label="Thursday 12 March" :selected="pickedDay === '12'" @pick="pickedDay = '12'" />
                            <DayButton weekday="Fri" day-of-month="13" full-label="Friday 13 March" :selected="pickedDay === '13'" @pick="pickedDay = '13'" />
                            <DayButton weekday="Sat" day-of-month="14" full-label="Saturday 14 March" :available="false" unavailable-reason="no times" />
                            <DayButton weekday="Sun" day-of-month="15" full-label="Sunday 15 March" :available="false" unavailable-reason="closed" />
                        </div>
                    </State>
                </Specimen>

                <Specimen
                    name="ChoiceRow and QuietAction"
                    note="A hairline row that is a whole choice, and the quietest control in the library. `ChoiceRow` is the booking page's alternatives — complete appointments, nothing contained, because they must not compete with the proposal they are alternatives to. `QuietAction` exists because &ldquo;the least important control on the page&rdquo; is a real role that every Button variant is too heavy for."
                >
                    <State name="Alternatives">
                        <ul class="max-w-md">
                            <li><ChoiceRow label="Tuesday, later" meta="11:30 · Ana" /></li>
                            <li><ChoiceRow label="Wednesday morning" meta="09:15 · Marek" /></li>
                            <li><ChoiceRow label="Thursday afternoon" meta="14:00 · Ana" /></li>
                        </ul>
                    </State>
                    <State name="Quiet actions">
                        <div class="flex items-center gap-6">
                            <QuietAction>Pick another day</QuietAction>
                            <QuietAction tone="ink">Search notes as well</QuietAction>
                            <QuietAction href="#quietaction">A link, because it navigates</QuietAction>
                        </div>
                    </State>
                </Specimen>

                <Specimen
                    name="Countdown"
                    note="A live `mm:ss`, mono and tabular so the digits do not shuffle sideways every second. `aria-live` is off and the role is `timer`: a screen reader announcing a new number once a second is unusable, and the expiry is stated once in prose beside it."
                >
                    <State name="Running, and expired">
                        <div class="flex items-center gap-8 text-17">
                            <span>Yours for <Countdown :expires-at="offerExpiry" /></span>
                            <span class="text-ink-2">Ran out at <Countdown expires-at="2020-01-01T00:00:00Z" /></span>
                        </div>
                    </State>
                </Specimen>

                <Specimen
                    name="SaveState"
                    note="Whether the form in front of you has been saved. Settings had a Save button and nothing else, so there was no way to tell a successful save from a click that missed — which is how people end up pressing Save twice and reloading to check."
                >
                    <State name="Unsaved, saving, saved">
                        <div class="space-y-2">
                            <SaveState :dirty="true" :processing="false" />
                            <SaveState :dirty="false" :processing="true" />
                            <SaveState :dirty="false" :processing="false" :saved-at="savedAt" />
                        </div>
                    </State>
                </Specimen>

                <Specimen
                    name="FileDrop"
                    note="A file, chosen by dropping it or by pressing it. Drag is never the only way in — WCAG 2.2 requires a single-pointer alternative — so it is a real file input with a real label, in the tab order, that happens to accept a drop as well."
                >
                    <div class="grid gap-6 md:grid-cols-2">
                        <State name="Empty">
                            <FileDrop label="Customer CSV" accept=".csv" hint="Columns, in order: name, email, phone, subjects" />
                        </State>
                        <State name="Chosen, and an error">
                            <FileDrop label="Customer CSV" accept=".csv" file-name="clients-export.csv" error="That file has no rows in it." />
                        </State>
                    </div>
                </Specimen>

                <Specimen
                    name="StaffColourField"
                    note="Six presets, not a colour wheel. The old form was a native colour picker, which guarantees that some salon eventually ships a neon-yellow groomer — the same argument DESIGN.md makes for tenant brand presets. The colour is never the only thing carrying the choice: every swatch has a name."
                >
                    <State name="Choosing">
                        <div class="max-w-md"><StaffColourField v-model="staffColour" /></div>
                    </State>
                </Specimen>

                <Specimen name="KeyHint" note="Shown in passing so the app teaches its own shortcuts.">
                    <State name="Keys">
                        <div class="flex items-center gap-6">
                            <KeyHint :keys="['⌘', 'K']" />
                            <KeyHint :keys="['Esc']" />
                            <KeyHint :keys="['↑', '↓']" />
                        </div>
                    </State>
                </Specimen>
            </div>
        </div>
    </div>
</template>
