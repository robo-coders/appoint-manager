@include('public-shell', [
    'vite' => ['resources/js/manage.ts'],
    'mount' => 'manage-app',
    'propsId' => 'manage-props',
    'stripe' => false,
])
