<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php
            $place = trim(collect([$tenant->city, $tenant->postcode])->filter()->implode(', '));
            $pageTitle = $place ? $tenant->name.' — book in '.$place : $tenant->name.' — book an appointment';
            $pageDescription = $place
                ? 'Book with '.$tenant->name.' in '.$place.'. Pick a service and a time that works.'
                : 'Book with '.$tenant->name.'. Pick a service and a time that works.';
            $jsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => $tenant->name,
                'url' => url()->current(),
                'telephone' => $tenant->phone,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $tenant->address_line_1,
                    'addressLocality' => $tenant->city,
                    'postalCode' => $tenant->postcode,
                    'addressCountry' => $tenant->country ?? 'GB',
                ]),
            ];
        @endphp
        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $pageDescription }}">
        @include('partials.head')
        @vite($vite)
        <script type="application/ld+json">{!! safe_json($jsonLd) !!}</script>
    </head>
    <body class="min-h-screen bg-paper font-sans text-ink antialiased">
        <header class="border-b border-rule bg-white px-4 py-4">
            <p class="mx-auto max-w-md text-13 font-medium">{{ $tenant->name }}</p>
            @if($place)
                <p class="mx-auto max-w-md text-12 text-ink-2">{{ $place }}</p>
            @endif
        </header>
        <main class="mx-auto w-full max-w-md px-4 py-6">
            <div id="{{ $mount }}"></div>
        </main>
        <script type="application/json" id="{{ $propsId }}">{!! safe_json($props) !!}</script>
    </body>
</html>
