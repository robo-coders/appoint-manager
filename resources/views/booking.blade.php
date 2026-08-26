{{--
    The public booking page — the one surface that carries the tenant's accent.

    `brand` is passed here and nowhere else. `manage-booking` and `offer` share
    this shell and deliberately omit it: they are pages a customer reaches from
    a link about one specific booking, not the salon's shopfront.
--}}
@include('public-shell', [
    'vite' => ['resources/js/booking.ts'],
    'mount' => 'booking-app',
    'propsId' => 'booking-props',
    'stripe' => true,
    'brand' => $tenant->brandVariable(),
])
