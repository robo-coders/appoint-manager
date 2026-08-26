import GapButton from '@/Components/ui/GapButton.vue';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { PX_PER_MIN, gapsIn, type DiaryBooking } from '@/Components/Diary/diary';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/**
 * `ui/TimelineRow` is `dashboard.html`'s row, and the dashboard and the diary's
 * 375px agenda are both built from it. Its five tones are the five things a row
 * in a day can be, and each one is a rule from the approved mockup:
 *
 *   - past: muted, and **no detail** — history does not need reading
 *   - current: a 2px ink left border, a fill, and the one extra line
 *   - freed: the only coloured row, with its action inline
 *   - gap: open time, drawn as space
 *   - default: a hairline row
 */
describe('TimelineRow tones', () => {
    it('mutes a past row and drops its detail', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '09:00', title: 'Bramble — full groom', tone: 'past', detail: 'deposit paid' },
        });

        expect(wrapper.find('div').classes()).toContain('text-ink-2');
        expect(wrapper.text()).not.toContain('deposit paid');
        // It is still a row of a day, so it still says when and what.
        expect(wrapper.text()).toContain('09:00');
        expect(wrapper.text()).toContain('Bramble');
    });

    it('gives the current row a 2px ink left border, a fill, and its extra line', () => {
        const wrapper = mount(TimelineRow, {
            props: {
                time: '12:00',
                title: 'Pepper — puppy trim',
                tone: 'current',
                detail: 'In the chair 14 min · deposit paid · first visit',
            },
        });

        const li = wrapper.find('li');
        expect(li.classes()).toContain('border-l-2');
        expect(li.classes()).toContain('border-l-ink');
        expect(li.classes()).toContain('bg-paper-sunk');
        expect(wrapper.text()).toContain('In the chair 14 min');
    });

    it('gives the freed row the accent border and says Freed in accent', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '15:30', tone: 'freed' },
            slots: { default: 'Marlow cancelled, 60 min open' },
        });

        const li = wrapper.find('li');
        expect(li.classes()).toContain('border-l-2');
        expect(li.classes()).toContain('border-l-accent');

        const flag = wrapper.findAll('span').find((s) => s.text().trim() === 'Freed —');
        expect(flag?.classes()).toContain('text-accent');
    });

    it('leaves a default row on the page with only a hairline', () => {
        const wrapper = mount(TimelineRow, { props: { time: '16:15', title: 'Hazel — bath', amount: '£28.00' } });

        const li = wrapper.find('li');
        expect(li.classes()).toContain('border-b');
        expect(li.classes()).not.toContain('border-l-2');
        expect(li.classes()).not.toContain('bg-paper-sunk');
    });

    /*
     * The rule this guards is the one the diary bent on purpose: a past row
     * carries no *routine* detail, but a double-booking is not routine and it
     * does not stop mattering because the appointment has been and gone.
     */
    it('keeps the problem line on a past row even though it drops the detail', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '13:30', title: 'Alfie — full groom', tone: 'past', detail: 'deposit paid' },
            slots: { problem: 'Double-booked' },
        });

        expect(wrapper.text()).not.toContain('deposit paid');
        expect(wrapper.text()).toContain('Double-booked');
    });

    it('is a button only when it does something', () => {
        const inert = mount(TimelineRow, { props: { time: '09:00', title: 'Bramble' } });
        expect(inert.find('button').exists()).toBe(false);

        const live = mount(TimelineRow, {
            props: { time: '09:00', title: 'Bramble', interactive: true, ariaLabel: 'Open Bramble at 09:00' },
        });
        expect(live.find('button').attributes('aria-label')).toBe('Open Bramble at 09:00');

        live.find('button').trigger('click');
        expect(live.emitted('open')).toBeTruthy();
    });

    it('puts the money in mono so a column of them lines up', () => {
        const wrapper = mount(TimelineRow, { props: { time: '09:00', title: 'Bramble', amount: '£45.00' } });
        const money = wrapper.findAll('span').find((s) => s.text() === '£45.00');

        expect(money?.classes()).toContain('numeral');
    });
});

/**
 * `ui/GapButton` is the answer to "gap-finding is the daily job". A statistic
 * tells you a hole exists; this *is* the hole, at the size the hole is.
 */
describe('GapButton', () => {
    it('labels itself only once there is room for the label', () => {
        const short = mount(GapButton, { props: { minutes: 15, ariaLabel: '15 minutes free' } });
        const long = mount(GapButton, { props: { minutes: 90, ariaLabel: '90 minutes free' } });

        // A 15-minute gap labelled "15 min" is a label, not a gap.
        expect(short.text()).toBe('');
        expect(long.text()).toContain('90 min');
    });

    it('is announced in full, because visually it is a rectangle', () => {
        const wrapper = mount(GapButton, {
            props: { minutes: 60, ariaLabel: '60 minutes free with Ana from 13:00. Book it.' },
        });

        expect(wrapper.find('button').attributes('aria-label')).toBe('60 minutes free with Ana from 13:00. Book it.');
    });

    it('emits when pressed', async () => {
        const wrapper = mount(GapButton, { props: { minutes: 30, ariaLabel: '30 minutes free' } });

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('book')).toHaveLength(1);
    });
});

/**
 * The geometry behind the drawing. `gapsIn` decides which minutes are a gap and
 * `PX_PER_MIN` decides how tall it is — together they are the claim that a
 * 90-minute hole looks three times a 30-minute one.
 */
describe('gap geometry', () => {
    const booking = (start: string, end: string, over: Partial<DiaryBooking> = {}): DiaryBooking => ({
        id: Math.random(),
        staff_id: 1,
        staff_name: 'Ana',
        service_name: 'Full groom',
        customer_name: 'Naomi',
        subject_name: null,
        starts_at_local: `2026-08-26 ${start}`,
        ends_at_local: `2026-08-26 ${end}`,
        status: 'confirmed',
        deposit_status: 'none',
        source: 'manual',
        duration_minutes: 60,
        cancellation_reason: null,
        ...over,
    });

    it('is proportional: three times the minutes is three times the height', () => {
        expect(90 * PX_PER_MIN).toBeCloseTo(3 * (30 * PX_PER_MIN));
    });

    it('finds the holes between appointments inside working hours', () => {
        const gaps = gapsIn(1, [{ start: '09:00', end: '17:00' }], [
            booking('09:00', '10:30'),
            booking('13:00', '14:00'),
        ]);

        expect(gaps).toEqual([
            { staff_id: 1, starts_at: '10:30', minutes: 150 },
            { staff_id: 1, starts_at: '14:00', minutes: 180 },
        ]);
    });

    it('ignores a sliver nobody could sell', () => {
        const gaps = gapsIn(1, [{ start: '09:00', end: '10:00' }], [
            booking('09:00', '09:50'),
        ]);

        expect(gaps).toEqual([]);
    });

    /*
     * A freed slot is drawn as an accent block *and* is open time. Counting it
     * as a plain grey gap as well would report the same hour twice — once as a
     * hole and once as a thing to act on.
     */
    it('does not draw a grey gap underneath a freed slot', () => {
        const gaps = gapsIn(1, [{ start: '09:00', end: '17:00' }], [
            booking('09:00', '15:00'),
            booking('15:00', '17:00', { status: 'cancelled', is_freed: true, minutes: 120 }),
        ]);

        expect(gaps).toEqual([]);
    });

    it('does draw a gap where a cancellation is not a freed slot', () => {
        const gaps = gapsIn(1, [{ start: '09:00', end: '17:00' }], [
            booking('09:00', '15:00'),
            booking('15:00', '17:00', { status: 'cancelled', is_freed: false }),
        ]);

        expect(gaps).toEqual([{ staff_id: 1, starts_at: '15:00', minutes: 120 }]);
    });
});
