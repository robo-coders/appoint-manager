import VisitCadenceTimeline, { type CadenceMark } from '@/Components/VisitCadenceTimeline.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const mark = (overrides: Partial<CadenceMark> & { date: string }): CadenceMark => ({
    booking_id: Number(overrides.date.replace(/-/g, '')),
    label: overrides.date,
    time: '09:30',
    outcome: 'attended',
    service_name: 'Full groom',
    staff_name: 'Erin',
    paid: '£42.00',
    gap_days: null,
    ...overrides,
});

const mountTimeline = (visits: CadenceMark[]) => mount(VisitCadenceTimeline, { props: { visits } });

const marks = (wrapper: ReturnType<typeof mountTimeline>) => wrapper.findAll('[data-testid="cadence-mark"]');

describe('with no visits', () => {
    it('says so instead of drawing an axis', () => {
        const wrapper = mountTimeline([]);

        expect(wrapper.text()).toContain('No visits yet');
        expect(marks(wrapper)).toHaveLength(0);
        expect(wrapper.find('[data-testid="cadence-detail"]').exists()).toBe(false);
    });
});

describe('with one visit', () => {
    it('draws a single point and computes no gap against a second that does not exist', () => {
        const wrapper = mountTimeline([mark({ date: '2026-03-12' })]);

        expect(marks(wrapper)).toHaveLength(1);
        expect(marks(wrapper)[0].attributes('style')).toContain('left: 50%');
        expect(wrapper.findAll('[data-testid="cadence-gap"]')).toHaveLength(0);
        expect(wrapper.find('[data-testid="cadence-detail"]').text()).toContain('Full groom');
    });
});

describe('with many visits', () => {
    const visits = [
        mark({ date: '2026-01-01' }),
        mark({ date: '2026-03-02', gap_days: 60, service_name: 'Bath and tidy' }),
        mark({ date: '2026-05-01', gap_days: 60, outcome: 'no_show', service_name: 'Nail clip' }),
        mark({ date: '2026-09-24', gap_days: 146, outcome: 'booked', service_name: 'Full groom' }),
    ];

    it('draws one mark per visit', () => {
        expect(marks(mountTimeline(visits))).toHaveLength(4);
    });

    it('labels the gap that stands out rather than every one', () => {
        const wrapper = mountTimeline(visits);

        expect(wrapper.findAll('[data-testid="cadence-gap"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('146d');
        expect(wrapper.text()).not.toContain('60d');
    });

    it('labels the only gap there is when there are just two visits', () => {
        const wrapper = mountTimeline([visits[0], visits[1]]);

        expect(wrapper.findAll('[data-testid="cadence-gap"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('60d');
    });

    it('draws no label for two appointments on the same day', () => {
        const wrapper = mountTimeline([
            mark({ date: '2026-03-12' }),
            mark({ date: '2026-03-12', booking_id: 99, gap_days: 0 }),
        ]);

        expect(wrapper.findAll('[data-testid="cadence-gap"]')).toHaveLength(0);
    });

    it('gives every month a label over a short span and every third over a long one', () => {
        const short = mountTimeline([mark({ date: '2026-07-23' }), mark({ date: '2026-09-14', gap_days: 53 })]);

        expect(short.text()).toContain('Aug');
        expect(short.text()).toContain('Sep');

        const long = mountTimeline([mark({ date: '2024-01-10' }), mark({ date: '2026-09-14', gap_days: 978 })]);

        expect(long.text()).toContain('Jan 25');
        expect(long.text()).not.toContain('Feb');
    });

    it('positions the first and last mark at the ends of the axis', () => {
        const wrapper = mountTimeline(visits);
        const positions = marks(wrapper).map((node) => node.attributes('style') ?? '');

        expect(positions[0]).toContain('left: 4%');
        expect(positions[3]).toContain('left: 96%');
    });

    it('starts on the most recent visit and follows the pointer', async () => {
        const wrapper = mountTimeline(visits);

        expect(wrapper.find('[data-testid="cadence-detail"]').text()).toContain('Booked');

        await marks(wrapper)[2].trigger('mouseenter');

        const detail = wrapper.find('[data-testid="cadence-detail"]').text();

        expect(detail).toContain('Nail clip');
        expect(detail).toContain('No show');
    });

    it('links every mark to its own booking', () => {
        const wrapper = mountTimeline(visits);

        expect(marks(wrapper)[0].get('a').attributes('href')).toContain('/bookings/show/20260101');
    });
});
