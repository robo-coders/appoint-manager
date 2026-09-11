
export type StaffMember = { id: number; name: string; colour: string | null; is_bookable: boolean };

export type DiaryBooking = {
    id: number;
    correlation_id?: string;
    staff_id: number;
    staff_name: string;
    service_name: string;
    customer_name: string;
    subject_name: string | null;
    starts_at_local: string;
    ends_at_local: string;
    status: string;
    deposit_status: string;
    source: string;
    duration_minutes: number | null;
    cancellation_reason: string | null;
    is_freed?: boolean;
    minutes?: number;
    gap_starts_at?: string | null;
    waiting?: number;
    offers_sent?: number;
    past?: boolean;
    current?: boolean;
    overrun_minutes?: number;
    overlapping?: boolean;
};

export type Gap = { staff_id: number; starts_at: string; minutes: number };

export const freedStart = (booking: DiaryBooking): string | null =>
    booking.gap_starts_at ? booking.gap_starts_at.slice(11, 16) : null;

export type Lane = { index: number; of: number };

export const PX_PER_MIN = 48 / 60;

export const minutesOf = (time: string): number => {
    const [h, m] = time.split(':').map(Number);

    return (h || 0) * 60 + (m || 0);
};

export const timeOf = (minutes: number): string =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

export const laneFor = (bookings: DiaryBooking[]): Map<number, Lane> => {
    const startOf = (b: DiaryBooking) => minutesOf(freedStart(b) ?? b.starts_at_local.slice(11));
    const endOf = (b: DiaryBooking) =>
        b.is_freed ? startOf(b) + (b.minutes ?? 0) : minutesOf(b.ends_at_local.slice(11));

    const sorted = [...bookings].sort((a, b) => startOf(a) - startOf(b));

    const out = new Map<number, Lane>();
    let cluster: DiaryBooking[] = [];
    let clusterEnd = -1;

    const flush = () => {
        if (cluster.length === 0) return;

        const laneEnds: number[] = [];

        for (const booking of cluster) {
            const start = startOf(booking);
            const end = endOf(booking);
            let lane = laneEnds.findIndex((finish) => finish <= start);

            if (lane === -1) {
                lane = laneEnds.length;
            }

            laneEnds[lane] = end;
            out.set(booking.id, { index: lane, of: 1 });
        }

        for (const booking of cluster) {
            const lane = out.get(booking.id);
            if (lane) out.set(booking.id, { index: lane.index, of: laneEnds.length });
        }

        cluster = [];
    };

    for (const booking of sorted) {
        const start = startOf(booking);

        if (start >= clusterEnd) {
            flush();
            clusterEnd = -1;
        }

        cluster.push(booking);
        clusterEnd = Math.max(clusterEnd, endOf(booking));
    }

    flush();

    return out;
};

export const gapsIn = (
    staffId: number,
    windows: Array<{ start: string; end: string }>,
    bookings: DiaryBooking[],
    minMinutes = 15,
): Gap[] => {
    const busy = bookings
        .filter((booking) => booking.staff_id === staffId && (booking.status !== 'cancelled' || booking.is_freed))
        .map((booking) => ({
            start: minutesOf(freedStart(booking) ?? booking.starts_at_local.slice(11)),
            end: booking.is_freed
                ? minutesOf(freedStart(booking) ?? booking.starts_at_local.slice(11)) + (booking.minutes ?? 0)
                : minutesOf(booking.ends_at_local.slice(11)),
        }))
        .sort((a, b) => a.start - b.start);

    const gaps: Gap[] = [];

    for (const window of windows) {
        let cursor = minutesOf(window.start);
        const finish = minutesOf(window.end);

        for (const block of busy) {
            if (block.end <= cursor || block.start >= finish) continue;

            if (block.start - cursor >= minMinutes) {
                gaps.push({ staff_id: staffId, starts_at: timeOf(cursor), minutes: block.start - cursor });
            }

            cursor = Math.max(cursor, block.end);
        }

        if (finish - cursor >= minMinutes) {
            gaps.push({ staff_id: staffId, starts_at: timeOf(cursor), minutes: finish - cursor });
        }
    }

    return gaps;
};

export const annotate = (bookings: DiaryBooking[], now: string | null): DiaryBooking[] => {
    const minute = now === null ? null : minutesOf(now);

    return bookings.map((booking) => {
        const start = minutesOf(booking.starts_at_local.slice(11));
        const end = minutesOf(booking.ends_at_local.slice(11));
        const booked = end - start;
        const scheduled = booking.duration_minutes ?? booked;

        return {
            ...booking,
            past: minute !== null && end <= minute && booking.status !== 'cancelled',
            current: minute !== null && start <= minute && end > minute && booking.status !== 'cancelled',
            overrun_minutes: booked > scheduled ? booked - scheduled : 0,
            overlapping: bookings.some(
                (other) =>
                    other.id !== booking.id &&
                    other.staff_id === booking.staff_id &&
                    other.status !== 'cancelled' &&
                    booking.status !== 'cancelled' &&
                    minutesOf(other.starts_at_local.slice(11)) < end &&
                    minutesOf(other.ends_at_local.slice(11)) > start,
            ),
        };
    });
};
