import DayGrid from '@/Components/Diary/DayGrid.vue';
import { PX_PER_MIN, minutesOf, type DiaryBooking, type StaffMember } from '@/Components/Diary/diary';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';

const staff: StaffMember[] = [{ id: 1, name: 'Ana Duarte', colour: null, is_bookable: true }];

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

        expect(top + 6).toBeLessThanOrEqual(height);
    });

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
        expect(visible).toContain('09:00');
        expect(visible).toContain('18:00');
    });
});

describe('the four-line freed row', () => {
    it('gives the title a width floor so the action can wrap below it', () => {
        const wrapper = mount(TimelineRow, {
            props: { time: '15:00', tone: 'freed' },
            slots: {
                default: 'Gil Beckett cancelled, 90 min open',
                action: () => h('button', { type: 'button' }, 'Offer to 3 waiting'),
            },
        });

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
