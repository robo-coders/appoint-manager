import forms from '@tailwindcss/forms';

/**
 * Colour, type, radius and shadow are REPLACED, not extended. That is
 * deliberate: `text-gray-600`, `rounded-lg`, `shadow-md` and `text-sm` compile
 * to nothing rather than silently working, so off-token values cannot creep
 * back in. `npm run check:design` turns that silence into a build failure.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.ts',
    ],

    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            inherit: 'inherit',

            paper: 'var(--paper)',
            'paper-sunk': 'var(--paper-sunk)',
            white: 'var(--white)',

            ink: 'var(--ink)',
            'ink-2': 'var(--ink-2)',
            'ink-3': 'var(--ink-3)',
            'ink-4': 'var(--ink-4)',
            'ink-tint': 'var(--ink-tint)',

            rule: 'var(--rule)',
            'rule-strong': 'var(--rule-strong)',
            'rule-hover': 'var(--rule-hover)',

            danger: 'var(--danger)',
            accent: 'var(--accent)',
            'accent-tint': 'var(--accent-tint)',

            'status-confirmed': 'var(--status-confirmed)',
            'status-pending': 'var(--status-pending)',
            'status-cancelled': 'var(--status-cancelled)',

            overlay: 'var(--overlay)',

            /* Set per tenant on the public booking page only. Defined in
               tokens.css, where it defaults to ink. */
            brand: 'var(--brand)',
            'brand-fg': 'var(--brand-fg)',
        },

        fontSize: {
            12: ['var(--text-12)', { lineHeight: '1.45', letterSpacing: 'var(--tracking-body)' }],
            13: ['var(--text-13)', { lineHeight: '1.5', letterSpacing: 'var(--tracking-body)' }],
            14: ['var(--text-14)', { lineHeight: 'var(--leading-body)', letterSpacing: 'var(--tracking-body)' }],
            15: ['var(--text-15)', { lineHeight: 'var(--leading-body)', letterSpacing: 'var(--tracking-body)' }],
            17: ['var(--text-17)', { lineHeight: 'var(--leading-heading)', letterSpacing: 'var(--tracking-17)' }],
            20: ['var(--text-20)', { lineHeight: 'var(--leading-heading)', letterSpacing: 'var(--tracking-20)' }],
            24: ['var(--text-24)', { lineHeight: '1.2', letterSpacing: 'var(--tracking-24)' }],
            34: ['var(--text-34)', { lineHeight: 'var(--leading-display)', letterSpacing: 'var(--tracking-34)' }],
            field: ['var(--field-text)', { lineHeight: '1.4', letterSpacing: 'var(--tracking-body)' }],
        },

        fontWeight: { normal: '400', medium: '500' },

        boxShadow: { none: 'none', ring: 'var(--focus-ring)' },

        // 6px on everything. `rounded-none` exists only for table rows and
        // list items, which are square by design.
        borderRadius: { none: '0px', DEFAULT: 'var(--radius)', sm: 'var(--radius)', md: 'var(--radius)' },

        letterSpacing: {
            34: 'var(--tracking-34)',
            24: 'var(--tracking-24)',
            20: 'var(--tracking-20)',
            17: 'var(--tracking-17)',
            body: 'var(--tracking-body)',
        },

        extend: {
            fontFamily: { sans: 'var(--font-sans)', display: 'var(--font-sans)', mono: 'var(--font-mono)' },
            transitionDuration: { DEFAULT: 'var(--duration)', fast: 'var(--duration-fast)' },
            transitionTimingFunction: { DEFAULT: 'var(--ease)', product: 'var(--ease)' },
            minHeight: { tap: 'var(--tap)', control: 'var(--control-h)', row: 'var(--row-h)' },
            // A floor, so a wrapping row drops its action onto its own line
            // rather than squeezing the text beside it into a column.
            minWidth: { 'col-when': 'var(--col-when)' },
            height: {
                control: 'var(--control-h)',
                row: 'var(--row-h)',
                badge: 'var(--badge-h)',
                skeleton: 'var(--skeleton-h)',
                topbar: 'var(--topbar)',
            },
            width: {
                rail: 'var(--rail)',
                // Square marks and dots that must match a badge's height.
                badge: 'var(--badge-h)',
                'rail-collapsed': 'var(--rail-collapsed)',
                // Bookings-table columns, so the loading skeleton can be shaped
                // to the real table rather than to three generic bars.
                'col-when': 'var(--col-when)',
                'col-time': 'var(--col-time)',
                'col-staff': 'var(--col-staff)',
                'col-status': 'var(--col-status)',
                'col-amount': 'var(--col-amount)',
                'col-actions': 'var(--col-actions)',
            },
            padding: {
                rail: 'var(--rail)',
                'rail-collapsed': 'var(--rail-collapsed)',
                'sub-indent': 'var(--sub-indent)',
            },
            maxWidth: {
                measure: 'var(--measure)',
                booking: 'var(--booking-w)',
                // A name that truncates caps at its column rather than filling it.
                'col-when': 'var(--col-when)',
                'col-staff': 'var(--col-staff)',
                'col-status': 'var(--col-status)',
            },
            // Long salon names and long dates in 34px want a balanced break
            // rather than a one-word last line.
            textWrap: { balance: 'balance' },
            spacing: {
                1: 'var(--space-1)',
                2: 'var(--space-2)',
                3: 'var(--space-3)',
                4: 'var(--space-4)',
                6: 'var(--space-6)',
                8: 'var(--space-8)',
                12: 'var(--space-12)',
                16: 'var(--space-16)',
                rhythm: 'var(--rhythm)',
                'pad-x': 'var(--pad-x)',
            },
        },
    },

    plugins: [forms],
};
