export type BookingBadgeTone = 'accent' | 'confirmed' | 'neutral' | 'cancelled';

export const BOOKING_STATUS_LABELS: Record<string, string> = {
    pending: 'Awaiting deposit',
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
    declined: 'Declined',
    completed: 'Completed',
    no_show: 'No show',
};

export const bookingStatusLabel = (status: string): string => BOOKING_STATUS_LABELS[status] ?? status;

export const bookingStatusTone = (status: string): BookingBadgeTone => {
    if (status === 'pending') return 'accent';
    if (status === 'confirmed') return 'confirmed';
    if (status === 'completed') return 'neutral';

    return 'cancelled';
};

export const DEPOSIT_STATUS_LABELS: Record<string, string> = {
    none: 'No deposit',
    required: 'Deposit due',
    paid: 'Deposit paid',
    refund_pending: 'Refund pending',
    refunded: 'Refunded',
};

export const depositStatusLabel = (status: string): string => DEPOSIT_STATUS_LABELS[status] ?? status;

export const bookingStatusStruck = (status: string): boolean =>
    ['cancelled', 'declined', 'no_show'].includes(status);

export const bookingWhenLabel = (value: string): string => {
    const date = new Date(`${value.replace(' ', 'T')}:00`);

    return Number.isNaN(date.getTime())
        ? value
        : `${date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} ${value.slice(11)}`;
};
