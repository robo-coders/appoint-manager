import DayGrid from '@/Components/Diary/DayGrid.vue';
import { PX_PER_MIN, minutesOf, type DiaryBooking, type StaffMember } from '@/Components/Diary/diary';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';

/**
 * The two bugs the phase 7 report says were found by taking a screenshot and
 * looking at it.
 *
 * Both were invisible to `vue-tsc`, both were invisible to the Pest suite, and
 * both are one assertion each once somebody decides to make the assertion. This
 * file exists so neither can come back quietly.
 */

const staff: StaffMember[] = [{ id: 1, name: 'Ana Duarte', colour: null, is_bookable: true }];

/*
 * The grid has two sticky elements: the header's corner spacer and the time
 * gutter under it. The gutter is the one that carries a height, and it is the
 * one these tests are about.
 */
const gutter = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('.sticky').find((el) => (el.attributes('style') ?? '').includes('height'))!;

const gutterHeight = (wrapper: ReturnType<typeof mount>) =>
    Number(/height:\s*([\d.]+)px/.exec(gutter(wrapper).attributes('style') ?? '')?.[1]);

const booking = (over: Partial<DiaryBooking> = {}): DiaryBooking => ({
    id: 1,
    staff_id: 1,
    staff_name: 'Ana Duarte',
    service_name: 'Full groom',
    customer_name: 'Naomi Ellery',
    subject_name: 'Bramble',
    starts_at_local: '2026-08-26 09:00',
    ends_at_local: '2026-08-26 10:30',
    status: 'confirmed',
    deposit_status: 'paid',
    source: 'online',
    duration_minutes: 90,
    cancellation_reason: null,
    ...over,
});

describe('the clipped gutter label', () => {
    /*
     * The bug: the grid's height was exactly `(end - start) * PX_PER_MIN`, and
     * gutter labels are centred on their line with `-translate-y-1/2`. The last
     * one — the hour the day ends on — was therefore drawn half outside the box
     * and clipped. On screen it read as a day that stops at 17:something.
     */
    it('leaves room below the last hour for its own label', () => {
        const wrapper = mount(DayGrid, {
            props: {
                staff,
                bookings: [booking()],
                gaps: [],
                dayStart: '09:00',
                dayEnd: '18:00',
                now: null,
            },
        });

        const height = gutterHeight(wrapper);
        const span = (minutesOf('18:00') - minutesOf('09:00')) * PX_PER_MIN;

        expect(height).toBeGreaterThan(span);
    });

    it('draws the last hour label inside the box it is in', () => {
        const wrapper = mount(DayGrid, {
            props: { staff, bookings: [], gaps: [], dayStart: '09:00', dayEnd: '18:00', now: null },
        });

        const labels = gutter(wrapper).findAll('span');
        const last = labels[labels.length - 1];
        expect(last.text()).toBe('18:00');

        const height = gutterHeight(wrapper);
        const top = Number(/top:\s*([\d.]+)px/.exec(last.attributes('style') ?? '')?.[1]);

        // Half a 12px line below the label's centre has to fit.
        expect(top + 6).toBeLessThanOrEqual(height);
    });

    /*
     * The other half of the same fix: `now` and the hour it is nearest were
     * drawn 9px apart in a 56px gutter, which is one unreadable label. Now
     * wins — it is the one that moves.
     */
    it('drops the hour label that would collide with now', () => {
        const wrapper = mount(DayGrid, {
            props: { staff, bookings: [], gaps: [], dayStart: '09:00', dayEnd: '18:00', now: '14:51' },
        });

        const visible = gutter(wrapper)
            .findAll('span')
            .filter((span) => !(span.attributes('style') ?? '').includes('display: none'))
            .map((span) => span.text());

        expect(visible).toContain('14:51');
        expect(visible).not.toContain('15:00');
        // The hours that are nowhere near it are untouched.
        expect(visible).toContain('09:00');
        expect(visible).toContain('18:00');
    });
});

describe('the four-line freed row', () => {
    /*
     * The bug: at 375px a freed row carries an accent button, and the title next
     * to it had `min-w-0`. A flex child with `min-w-0` shrinks rather than
     * wrapping, so "Gil Beckett cancelled, 90 min open" was squeezed into a
     * four-line column beside the button instead of the button dropping below.
     *
     * The fix is a `min-w-col-when` floor plus `flex-wrap` on the row, and both
     * halves are needed: the floor alone has nowhere to go, and the wrap alone
     * never triggers.
     */
    it('gives the title a width floor so the action can wrap below it', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '15:00', tone: 'freed' },
            slots: {
                default: 'Gil Beckett cancelled, 90 min open',
                action: () => h('button', { type: 'button' }, 'Offer to 3 waiting'),
            },
        });

        // The title is the flexible child of the row, not the row itself.
        const title = wrapper.findAll('span').find((span) => span.classes().includes('flex-1'));
        expect(title).toBeDefined();
        expect(title!.text()).toContain('Gil Beckett');
        expect(title!.classes()).toContain('min-w-col-when');
        expect(title!.classes()).not.toContain('min-w-0');
    });

    it('lets the row wrap rather than shrinking its title', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '15:00', tone: 'freed' },
            slots: { default: 'Marlow cancelled', action: () => h('button', {}, 'Offer to 3 waiting') },
        });

        const row = wrapper.find('.flex');
        expect(row.classes()).toContain('flex-wrap');
    });
});
