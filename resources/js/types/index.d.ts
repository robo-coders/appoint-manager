import { UserRole } from './models';

export interface User {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    email_verified_at?: string | null;
}

/** Mirrors the `tenant` payload in HandleInertiaRequests::share(). */
export interface Tenant {
    id: number;
    name: string;
    slug: string;
    timezone: string;
    currency: string;
    onboarding_completed: boolean;
    read_only: boolean;
    trial_days_remaining: number;
    show_trial_banner: boolean;
}

export interface VerticalField {
    key: string;
    label: string;
    type: string;
    options?: string[];
    required: boolean;
}

export interface Vertical {
    label: string;
    subject_singular: string;
    subject_plural: string;
    customer_singular: string;
    appointment_singular: string;
    subject_fields: VerticalField[];
    default_services: Array<{
        name: string;
        duration_minutes: number;
        price: number;
        deposit_amount: number;
    }>;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    appName: string;
    auth: {
        user: User | null;
    };
    tenant: Tenant | null;
    vertical: Vertical;
    today: string | null;
    toast: string | null;
    sms: {
        used: number;
        included: number;
        prepaid: number;
        ceiling: number;
        remaining: number;
        percent: number;
        can_send: boolean;
        stopped: 'killed' | 'ceiling' | 'allowance' | null;
        warning: number | null;
        killed: boolean;
        topup_price: string;
        topup_size: number;
    } | null;
    impersonating: boolean;
    urls: { marketing: string; app: string; admin: string };
    /** The one sentence the auth surface's quiet column carries. Built in PHP. */
    auth_panel: { headline: string; body: string };
    /**
     * Stale CSRF / dropped session on the login form. Distinct from a wrong
     * password, which arrives as a validation error on `email`.
     */
    authNotice: { kind: 'expired'; title: string; body: string } | null;
    preview: unknown;
};
