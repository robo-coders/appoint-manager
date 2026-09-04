import BookingIsland from '@/Pages/Public/BookingIsland.vue';
import ManageIsland from '@/Pages/Public/ManageIsland.vue';
import OfferIsland from '@/Pages/Public/OfferIsland.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/**
 * The three public islands.
 *
 * The claim phase 5 makes is that these are one page wearing three hats: the
 * same 34px statement of one finished appointment, the same single column, the
 * same primary button. That claim is testable, and until now nothing tested it.
 *
 * The other claim is the proposal model itself — no calendar, one appointment,
 * three spread ways out, and a reason attached to each. Also testable.
 */

const money = (amount: number, formatted: string) => ({ amount, formatted, currency: 'GBP' });

const proposal = (over: Record<string, unknown> = {}) => ({
    starts_at: '2026-03-10T09:45:00+00:00',
    date: '2026-03-10',
    day: 'Tuesday 10 March',
    time: '09:45',
    ends_time: '11:15',
    service_id: 1,
    service_name: 'Full groom',
    duration_minutes: 90,
    price: money(4500, '£45.00'),
    deposit: money(1500, '£15.00'),
    staff_id: 1,
    staff_name: 'Ana Duarte',
    staff_first_name: 'Ana',
    staff_ids: [1],
    subject_id: null,
    subject_name: null,
    reason: 'Your usual Tuesday',
    reason_key: 'usual_day',
    day_label: 'Tuesday 10 March',
    cost_line: '£45.00 total, £15.00 deposit due today',
    free_until: 'Sunday 8 March',
    action_label: 'Reserve Tuesday at 09:45',
    meta: '09:45 · Ana',
    ...over,
});

const bookingProps = (over: Record<string, unknown> = {}) => ({
    tenant: {
        name: 'Willow Street Grooming',
        slug: 'willow-street-grooming',
        timezone: 'Europe/London',
        currency: 'GBP',
        takes_deposits: true,
        booking_mode: 'automated',
        request_requires_deposit: true,
        request_sent_message: 'Request sent. Willow Street Grooming will confirm within a day.',
    },
    stripePublishableKey: null,
    services: [{ id: 1, name: 'Full groom', duration_minutes: 90, price: money(4500, '£45.00'), deposit_amount: money(1500, '£15.00') }],
    suggestion: {
        primary: proposal(),
        alternatives: [
            proposal({ reason: 'Tuesday, later', time: '11:30', meta: '11:30 · Ana', starts_at: '2026-03-10T11:30:00+00:00' }),
            /*
             * A different groomer, and the fields now agree that it is one.
             * This row already read "09:15 · Marek" while carrying Ana's
             * `staff_id` and `staff_name` — the fixture said two things at once,
             * and the page could not have told the difference either way because
             * nothing looked at the id. Something does now.
             */
            proposal({
                reason: 'Wednesday morning',
                time: '09:15',
                meta: '09:15 · Marek',
                starts_at: '2026-03-11T09:15:00+00:00',
                staff_id: 2,
                staff_name: 'Marek Kowalski',
                staff_first_name: 'Marek',
                staff_ids: [2],
            }),
            proposal({ reason: 'Thursday afternoon', time: '14:00', meta: '14:00 · Ana', starts_at: '2026-03-12T14:00:00+00:00' }),
        ],
        returning: false,
        customer_name: null,
        subject_name: null,
        interval_days: null,
        context: 'Your usual Tuesday · full groom · 90 min with Ana',
        timezone: 'Europe/London',
    },
    vertical: { subject_singular: 'dog', subject_fields: [], appointment_singular: 'groom' },
    today: '2026-03-10',
    urls: { page: '/book/x', availability: '/book/x/availability', store: '/book/x/bookings', waitlist: '/book/x/waitlist' },
    ...over,
});

describe('BookingIsland — the proposal', () => {
    it('states one finished appointment at 34px, and it is the largest thing on the page', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const heading = wrapper.find('h1');
        expect(heading.classes()).toContain('text-34');
        expect(heading.text()).toContain('Tuesday 10 March');
        expect(heading.text()).toContain('09:45');

        // Nothing else on the page is allowed to be that size.
        expect(wrapper.findAll('.text-34')).toHaveLength(1);
    });

    it('leads with the reason the suggester gave', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        expect(wrapper.text()).toContain('Your usual Tuesday · full groom · 90 min with Ana');
    });

    it('names the outcome on the primary button', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const primary = wrapper.findAll('button').find((b) => b.text().includes('Reserve'));
        expect(primary?.text()).toContain('Reserve Tuesday at 09:45');
    });

    it('states the cost as one line and the refund window as a date', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        expect(wrapper.text()).toContain('£45.00 total, £15.00 deposit due today');
        expect(wrapper.text()).toContain('Free to cancel or move until Sunday 8 March');
    });

    it('says the deposit is not refundable when the cut-off has gone, rather than a date in the past', () => {
        const wrapper = mount(BookingIsland, {
            props: bookingProps({
                suggestion: { ...bookingProps().suggestion, primary: proposal({ free_until: null }) },
            }),
        });

        expect(wrapper.text()).not.toContain('Free to cancel');
        expect(wrapper.text()).toContain('deposit is not refundable');
    });

    it('offers three alternatives, each a complete appointment', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const rows = wrapper.findAll('li button');
        expect(rows).toHaveLength(3);

        expect(rows.map((r) => r.text())).toEqual([
            expect.stringContaining('Tuesday, later'),
            expect.stringContaining('Wednesday morning'),
            expect.stringContaining('Thursday afternoon'),
        ]);
        // A time and a person, so the row is a whole appointment.
        expect(rows[0].text()).toContain('11:30 · Ana');
    });

    /*
     * WCAG 2.5.3, Label in Name. The alternatives once carried an `aria-label`
     * that reworded the visible text, so a speech-input user saying "Wednesday
     * morning" activated nothing. Lighthouse caught it; this keeps it caught.
     */
    it('never gives an alternative an accessible name that hides its visible text', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        for (const row of wrapper.findAll('li button')) {
            const label = row.attributes('aria-label');
            if (label === undefined) continue;

            expect(label).toContain(row.text().split('\n')[0].trim());
        }
    });

    /*
     * `AppointmentSuggester` ranks appointments, and an appointment is a time
     * *and* a person — so an alternative at a time the proposed groomer cannot
     * work is an alternative with somebody else. The page used to say so only by
     * putting a different first name in the muted column, three rows under a
     * context line naming the groomer being proposed, and a customer scanning
     * four near-identical rows had to hold "Ana" in their head and compare.
     *
     * That is the `resolveStaff()` silent-reassignment behaviour recorded in
     * DECISIONS.md becoming visible in the UI. The booking behaviour is
     * deliberately unchanged; what is tested here is that the page stops being
     * quiet about it.
     */
    it('says when an alternative is with a different groomer, and who', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const rows = wrapper.findAll('li button');

        expect(rows[1].text()).toContain('with Marek instead of Ana');
    });

    it('says nothing extra about an alternative that keeps the proposed groomer', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const rows = wrapper.findAll('li button');

        expect(rows[0].text()).not.toContain('instead of');
        expect(rows[2].text()).not.toContain('instead of');
    });

    /*
     * The reason this phrase is composed in the island rather than in
     * `ProposalPayload`, stated as a test.
     *
     * Accepting an alternative makes it the proposal and pushes the old proposal
     * back into this list, so "different from what is proposed" changes meaning
     * on a click. Built server-side, the notes would still be describing the
     * groomer from the first render — and after accepting Marek the two Ana rows
     * would carry no note at all, which is precisely backwards.
     */
    it('re-points the groomer note when an alternative is accepted', async () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const marek = wrapper.findAll('li button').find((row) => row.text().includes('Wednesday morning'));
        await marek!.trigger('click');

        // Marek is the proposal now, so the rows that stayed with Ana are the
        // ones that change the groomer.
        const rows = wrapper.findAll('li button');
        expect(rows.some((row) => row.text().includes('with Ana instead of Marek'))).toBe(true);
        expect(rows.every((row) => !row.text().includes('instead of Ana'))).toBe(true);
    });

    /*
     * The page picks a service — the customer's usual, or the salon's first —
     * and nine were reachable only by opening the day picker and scrolling past
     * a week grid. A customer whose dog needs a hand strip could not find that.
     */
    it('offers a way to change service without opening the picker', async () => {
        const wrapper = mount(BookingIsland, {
            props: bookingProps({
                services: [
                    { id: 1, name: 'Full groom', duration_minutes: 90, price: money(4500, '£45.00'), deposit_amount: money(1500, '£15.00') },
                    { id: 2, name: 'Nail clip', duration_minutes: 15, price: money(1200, '£12.00'), deposit_amount: money(0, '£0.00') },
                ],
            }),
        });

        const open = wrapper.findAll('button').find((b) => b.text() === 'A different service');
        expect(open).toBeDefined();
        // A disclosure, so it says whether it is open — and it starts shut, so
        // the proposal is the only thing competing for attention on load.
        expect(open!.attributes('aria-expanded')).toBe('false');
        expect(wrapper.text()).not.toContain('Nail clip');

        await open!.trigger('click');

        expect(open!.attributes('aria-expanded')).toBe('true');
        expect(wrapper.text()).toContain('Nail clip');
        // Still a list, not a form, and no calendar has appeared.
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Pick a day');
    });

    /** The one service already on offer is marked, not silently dropped. */
    it('marks the service being proposed in the list of the others', async () => {
        const wrapper = mount(BookingIsland, {
            props: bookingProps({
                services: [
                    { id: 1, name: 'Full groom', duration_minutes: 90, price: money(4500, '£45.00'), deposit_amount: money(1500, '£15.00') },
                    { id: 2, name: 'Nail clip', duration_minutes: 15, price: money(1200, '£12.00'), deposit_amount: money(0, '£0.00') },
                ],
            }),
        });

        await wrapper.findAll('button').find((b) => b.text() === 'A different service')!.trigger('click');

        const rows = wrapper.findAll('li button');
        const fullGroom = rows.find((row) => row.text().includes('Full groom'));
        const nailClip = rows.find((row) => row.text().includes('Nail clip'));

        expect(fullGroom!.text()).toContain('The one on offer above');
        expect(nailClip!.text()).not.toContain('The one on offer above');
    });

    it('has no calendar on it', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        expect(wrapper.text()).not.toContain('Pick a day');
        expect(wrapper.findAll('[role="group"]')).toHaveLength(0);
    });

    it('keeps the picker behind the quietest control on the page', () => {
        const wrapper = mount(BookingIsland, { props: bookingProps() });

        const quiet = wrapper.findAll('button').find((b) => b.text() === 'Pick another day');
        expect(quiet).toBeDefined();
        expect(quiet!.classes()).toContain('text-13');
        expect(quiet!.classes()).toContain('text-ink-2');
        expect(quiet!.classes()).toContain('min-h-tap');
    });

    /*
     * Nothing bookable is the one screen where the waitlist is the primary
     * action rather than a footnote. A page that just says "no times" loses the
     * customer; this one keeps them.
     */
    it('offers the waitlist, not an empty picker, when there is nothing to propose', () => {
        const wrapper = mount(BookingIsland, {
            props: bookingProps({
                suggestion: { ...bookingProps().suggestion, primary: null, alternatives: [] },
            }),
        });

        expect(wrapper.text()).toContain('fully booked');
        expect(wrapper.findAll('button').some((b) => b.text().includes('Text me when something opens'))).toBe(true);
    });
});

describe('ManageIsland — the same page, a different hat', () => {
    const manageProps = (over: Record<string, unknown> = {}) => ({
        booking: {
            public_token: 'abc',
            service_name: 'Full groom',
            staff_name: 'Ana Duarte',
            customer_name: 'Naomi Ellery',
            subject_name: 'Bramble',
            starts_at: '2026-03-10T09:45:00+00:00',
            starts_at_local: '2026-03-10 09:45',
            ends_at_local: '2026-03-10 11:15',
            status: 'confirmed',
            deposit_status: 'paid',
            price_at_booking: money(4500, '£45.00'),
            deposit_at_booking: money(1000, '£10.00'),
            duration_minutes: 90,
            day_label: 'Tuesday 10 March',
            time: '09:45',
            cost_line: '£45.00 total, £10.00 deposit paid',
            free_until: 'Sunday 8 March',
            context: 'Full groom for Bramble · 90 min with Ana',
        },
        tenant: { name: 'Willow Street Grooming', timezone: 'Europe/London', address: '1 Willow St', phone: '01422 000000' },
        can_cancel: true,
        can_reschedule: true,
        cancel_consequence: 'Cancel and refund £10.00',
        urls: { cancel: '/c', reschedule: '/r', availability: '/a' },
        ...over,
    });

    it('states the appointment in the same 34px heading the booking page uses', () => {
        const wrapper = mount(ManageIsland, { props: manageProps() });

        const heading = wrapper.find('h1');
        expect(heading.classes()).toContain('text-34');
        expect(heading.text()).toContain('Tuesday 10 March');
        expect(heading.text()).toContain('09:45');
    });

    /*
     * The consequence goes *before* the confirm. "Cancel and refund £10" and
     * "Cancel — the £10 deposit is not refunded this close" are two different
     * decisions, and which one is being made has to be legible on the control
     * being pressed, not in the dialog that follows it.
     */
    it('puts the consequence on the control, not behind it', () => {
        const wrapper = mount(ManageIsland, { props: manageProps() });

        expect(wrapper.text()).toContain('Cancel and refund £10.00');
    });

    it('states the other consequence when the deposit is not coming back', () => {
        const wrapper = mount(ManageIsland, {
            props: manageProps({
                cancel_consequence: 'Cancel — the £10.00 deposit is not refunded this close to the appointment',
            }),
        });

        expect(wrapper.text()).toContain('is not refunded this close to the appointment');
    });

    it('explains why moving is unavailable rather than silently omitting the button', () => {
        const wrapper = mount(ManageIsland, { props: manageProps({ can_reschedule: false, booking: { ...manageProps().booking, free_until: null } }) });

        expect(wrapper.text()).toContain('too close to the appointment to move online');
        expect(wrapper.text()).toContain('01422 000000');
    });

    it('shows one statement and no dead controls once cancelled', () => {
        const wrapper = mount(ManageIsland, {
            props: manageProps({ booking: { ...manageProps().booking, status: 'cancelled' } }),
        });

        expect(wrapper.find('h1').text()).toBe('Cancelled');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('OfferIsland — taken is a designed state', () => {
    const offerProps = (over: Record<string, unknown> = {}) => ({
        offer: {
            token: 'tok',
            status: 'sent',
            starts_at: '2026-03-10T15:30:00+00:00',
            day_label: 'Tuesday 10 March',
            weekday: 'Tuesday',
            time: '15:30',
            service_name: 'Full groom',
            expires_at: new Date(Date.now() + 20 * 60 * 1000).toISOString(),
            claimable: true,
            context: 'A slot has opened up · full groom · 90 min with Marek',
            cost_line: '£45.00, pay on the day',
        },
        fallback: {
            day_label: 'Thursday 12 March',
            weekday: 'Thursday',
            time: '14:00',
            context: 'First available · full groom · 90 min with Ana',
            cost_line: '£45.00, pay on the day',
            reason: 'First available',
            meta: '14:00 · Ana',
            url: '/book/x?service=1',
        },
        needs_deposit: false,
        urls: { claim: '/claim', book: '/book/x' },
        stripePublishableKey: null,
        ...over,
    });

    it('states the freed appointment in the same 34px heading', () => {
        const wrapper = mount(OfferIsland, { props: offerProps() });

        const heading = wrapper.find('h1');
        expect(heading.classes()).toContain('text-34');
        expect(heading.text()).toContain('Tuesday 10 March');
    });

    it('runs a live countdown, because the whole mechanic is that it runs out', () => {
        const wrapper = mount(OfferIsland, { props: offerProps() });

        const timer = wrapper.find('[role="timer"]');
        expect(timer.exists()).toBe(true);
        expect(timer.text()).toMatch(/^\d{2}:\d{2}$/);
        // A number announced once a second is unusable; the expiry is stated
        // once in prose beside it instead.
        expect(timer.attributes('aria-live')).toBe('off');
    });

    /*
     * Somebody else being faster is the mechanic working, not a fault. So the
     * taken state is the same layout with the next appointment already
     * proposed — never a red alert telling a customer they did something wrong.
     */
    it('shows the next appointment in the same dominant type when the slot has gone', () => {
        const wrapper = mount(OfferIsland, {
            props: offerProps({ offer: { ...offerProps().offer, claimable: false } }),
        });

        expect(wrapper.find('h1').classes()).toContain('text-34');
        expect(wrapper.find('h1').text()).toContain('Thursday 12 March');
        expect(wrapper.text()).toContain('Somebody else took Tuesday 10 March');
        expect(wrapper.text()).toContain('You are still on the waitlist');

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.findAll('.text-danger')).toHaveLength(0);
    });

    it('still says something useful when there is no next appointment either', () => {
        const wrapper = mount(OfferIsland, {
            props: offerProps({ offer: { ...offerProps().offer, claimable: false }, fallback: null }),
        });

        expect(wrapper.find('h1').text()).toBe('Just gone');
        expect(wrapper.text()).toContain('still on the waitlist');
    });

    it('names the outcome on its one button', () => {
        const wrapper = mount(OfferIsland, { props: offerProps() });

        const claim = wrapper.findAll('button').find((b) => b.text().includes('Take'));
        expect(claim?.text()).toContain('Take Tuesday at');
        expect(claim?.text()).toContain('15:30');
    });
});

describe('the three islands agree with each other', () => {
    it('all state their appointment with the same heading treatment', () => {
        const heads = [
            mount(BookingIsland, { props: bookingProps() }).find('h1'),
            mount(ManageIsland, {
                props: {
                    booking: {
                        public_token: 'a', service_name: 'Full groom', staff_name: 'Ana', customer_name: 'N',
                        subject_name: null, starts_at: '2026-03-10T09:45:00+00:00', starts_at_local: '2026-03-10 09:45',
                        ends_at_local: '2026-03-10 11:15', status: 'confirmed', deposit_status: 'none',
                        price_at_booking: money(4500, '£45.00'), deposit_at_booking: money(0, '£0.00'),
                        duration_minutes: 90, day_label: 'Tuesday 10 March', time: '09:45',
                        cost_line: '£45.00, pay on the day', free_until: null, context: 'Full groom · 90 min with Ana',
                    },
                    tenant: { name: 'W', timezone: 'Europe/London', address: '', phone: null },
                    can_cancel: false, can_reschedule: false, cancel_consequence: '',
                    urls: { cancel: '/c', reschedule: '/r', availability: '/a' },
                },
            }).find('h1'),
        ];

        for (const head of heads) {
            expect(head.classes()).toContain('text-34');
            expect(head.classes()).toContain('font-medium');
        }
    });
});
