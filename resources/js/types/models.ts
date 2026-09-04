export type UserRole = 'owner' | 'staff';

/** Laravel `paginate()` as Inertia receives it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

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
    /** How long before this service is due again. Null means the product default. */
    suggested_interval_days: number | null;
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
