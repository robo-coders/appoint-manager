@include('public-shell', [
    'vite' => ['resources/js/booking.ts'],
    'mount' => 'booking-app',
    'propsId' => 'booking-props',
    'stripe' => true,
])
