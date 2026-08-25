export type UserRole = 'owner' | 'staff';

export interface Money {
    amount: number;
    formatted: string;
    currency: string;
}

export interface ServiceRecord {
    id: number;
    name: string;
    description: string | null;
    duration_minutes: number;
    buffer_minutes: number;
    price: Money;
    deposit_amount: Money;
    is_active: boolean;
    sort_order: number;
    staff_ids: number[];
}

export interface StaffRecord {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    is_bookable: boolean;
    is_active: boolean;
    colour: string | null;
}

export interface AvailabilityRange {
    user_id: number;
    weekday: number;
    start_time: string;
    end_time: string;
}
