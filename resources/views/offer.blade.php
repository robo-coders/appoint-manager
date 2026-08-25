@include('public-shell', [
    'vite' => ['resources/js/offer.ts'],
    'mount' => 'offer-app',
    'propsId' => 'offer-props',
    'stripe' => true,
])
