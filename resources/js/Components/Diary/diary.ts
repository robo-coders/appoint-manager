/**
 * The diary's geometry and vocabulary, in one place so the day grid and the
 * agenda cannot disagree about what a gap is.
 */

export type StaffMember = { id: number; name: string; colour: string | null; is_bookable: boolean };

export type DiaryBooking = {
    id: number;
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
    /** Set by `FreedSlots` on the server for cancelled rows. */
    is_freed?: boolean;
    minutes?: number;
    /** Local `Y-m-d H:i`. Where the still-open stretch begins; see `freedStart`. */
    gap_starts_at?: string | null;
    waiting?: number;
    offers_sent?: number;
    /** Worked out in the browser, where the whole day is in hand. */
    past?: boolean;
    current?: boolean;
    overrun_minutes?: number;
    overlapping?: boolean;
};

export type Gap = { staff_id: number; starts_at: string; minutes: number };

/**
 * When a freed slot actually begins.
 *
 * `FreedSlots` on the server reports the largest still-open stretch inside a
 * cancelled window, which is not always the window's own start: a 90-minute
 * cancellation with its first half hour already rebooked frees an hour that
 * begins thirty minutes late. Falling back to the booking's own start is right
 * for the ordinary case where nothing has been rebooked.
 *
 * `gap_starts_at` arrives as a local `Y-m-d H:i` string — the controller
 * converts it — for the same reason every other time on this screen does: the
 * browser does not know the salon's timezone and must not guess.
 */
export const freedStart = (booking: DiaryBooking): string | null =>
    booking.gap_starts_at ? booking.gap_starts_at.slice(11, 16) : null;

export type Lane = { index: number; of: number };

/**
 * Vertical scale.
 *
 * 0.8px a minute — a 48px hour — is the smallest scale at which a 15-minute nail
 * clip is still a visible block rather than a line, and it puts a 09:00–17:00
 * day in 384px, which fits on a laptop without scrolling. The old diary used the
 * same 48px hour; that part was right.
 */
export const PX_PER_MIN = 48 / 60;

/** `HH:MM` to minutes past midnight. */
export const minutesOf = (time: string): number => {
    const [h, m] = time.split(':').map(Number);

    return (h || 0) * 60 + (m || 0);
};

export const timeOf = (minutes: number): string =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

/**
 * Where each block sits when they overlap.
 *
 * A double-booking is a mistake worth seeing. Drawing both blocks at full width
 * hides one behind the other, which is how a diary tells you everything is fine
 * while somebody has two dogs booked at 13:30. Overlapping blocks split the
 * column between them instead.
 *
 * Greedy left-to-right: a block takes the first lane whose last occupant has
 * already finished. Blocks that never overlap anything all land in lane 0 and
 * take the whole column, so the common case costs nothing.
 */
export const laneFor = (bookings: DiaryBooking[]): Map<number, Lane> => {
    // The same extent the block is drawn at, so a freed slot that has already
    // been partly rebooked does not claim to overlap the appointment that took
    // its tail — it stops before it.
    const startOf = (b: DiaryBooking) => minutesOf(freedStart(b) ?? b.starts_at_local.slice(11));
    const endOf = (b: DiaryBooking) =>
        b.is_freed ? startOf(b) + (b.minutes ?? 0) : minutesOf(b.ends_at_local.slice(11));

    const sorted = [...bookings].sort((a, b) => startOf(a) - startOf(b));

    const out = new Map<number, Lane>();
    /** Clusters of mutually-overlapping bookings, resolved together. */
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

        // Everything in the cluster shares the same width, so the column is
        // divided once rather than per block — otherwise two blocks at 13:30
        // and 13:45 would be different widths for no reason a person can see.
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

/**
 * The holes in one person's day.
 *
 * Working windows minus the appointments in them. Anything shorter than
 * `minMinutes` is not a gap anybody can sell, so it is not drawn as one — a
 * five-minute sliver between two appointments is the diary's rounding, not an
 * opportunity.
 */
export const gapsIn = (
    staffId: number,
    windows: Array<{ start: string; end: string }>,
    bookings: DiaryBooking[],
    minMinutes = 15,
): Gap[] => {
    /*
     * Busy means "something is already drawn here", not "somebody is working".
     * A freed slot is a cancellation *and* an opportunity, and it is drawn as
     * an accent block in the grid — so drawing a plain grey gap underneath it
     * as well would report the same hour twice, once as a hole and once as a
     * thing to act on.
     */
    const busy = bookings
        .filter((booking) => booking.staff_id === staffId && (booking.status !== 'cancelled' || booking.is_freed))
        .map((booking) => ({
            // A freed slot occupies only what is genuinely still open — the
            // server measured it in `FreedSlots` — not the whole cancelled
            // window, part of which may already have been rebooked.
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

/**
 * Everything the browser can work out about a booking that the server should
 * not have to: whether it has been and gone, whether it is happening now,
 * whether it is running over its service's own length, and whether it collides
 * with another appointment on the same person.
 */
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
