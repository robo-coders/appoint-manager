import base from './tailwind.config.js';

/**
 * The public booking pages ship their own stylesheet so a customer on bad
 * signal never downloads the admin app's CSS. Same theme, narrower content.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    ...base,
    content: [
        './resources/views/public-shell.blade.php',
        './resources/views/booking.blade.php',
        './resources/views/manage-booking.blade.php',
        './resources/views/offer.blade.php',
        './resources/views/offer-taken.blade.php',
        './resources/views/errors/**/*.blade.php',
        './resources/js/Pages/Public/**/*.vue',
        './resources/js/Components/**/*.vue',
    ],
};
