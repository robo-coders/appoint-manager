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
            /*
             * The tenant's accent, or null. Only the booking page passes one;
             * `manage-booking` and `offer` deliberately do not, so `--brand`
             * keeps the ink default tokens.css gives it and those pages stay
             * monochrome.
             *
             * The value is `var(--brand-navy)` — a reference to a custom
             * property, never a colour. It is built by App\Support\BrandPalette
             * from a fixed list of six, which is what makes it safe to put in a
             * style attribute at all.
             */
            $brand = $brand ?? null;
            $initial = mb_strtoupper(mb_substr(trim($tenant->name), 0, 1));
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
    {{--
        `data-density="roomy"` is set here and only here. It is what makes every
        shared component on this surface 48px tall with 44px rows and 15px field
        text — a stranger, on a phone, once, possibly outdoors. No component on
        this page takes a size prop; they all read this one attribute.
    --}}
    <body
        class="min-h-screen bg-paper font-sans text-ink antialiased"
        data-density="roomy"
        @if($brand) style="--brand: {{ $brand }}" @endif
    >
        {{--
            Full-bleed, not floating. The surface runs to the edges of the phone
            and the header hairline crosses the whole screen; a 440px card with
            a shadow in the middle of a 375px viewport is a desktop layout that
            has been shrunk, and it reads like one.
        --}}
        <header class="border-b border-b-rule bg-white px-4 py-4">
            <div class="mx-auto flex max-w-booking items-center gap-2">
                {{--
                    Place one of two for the salon's colour. The other is the
                    primary button; there is no third.

                    The approved mockup draws a geometric mark here. It is the
                    salon's own initial instead — putting *our* logo on a
                    customer-facing shopfront is the one place the product must
                    not appear, and DESIGN.md says the same. 20px rather than the
                    mockup's 16, because a letter inside a 16px square is not a
                    letter any more. See DECISIONS.md.

                    aria-hidden because the salon's name is the very next
                    element: a screen reader announcing "W, Willow Street
                    Grooming" is reading the logo out loud as a letter.
                --}}
                <span
                    class="flex h-badge w-badge shrink-0 items-center justify-center rounded bg-brand text-12 font-medium text-brand-fg"
                    aria-hidden="true"
                >{{ $initial }}</span>
                <p class="truncate text-13 font-medium">{{ $tenant->name }}</p>
            </div>
        </header>

        {{-- 440px, centred, one thumb wide and never wider. --}}
        <main class="mx-auto w-full max-w-booking px-4 py-6">
            <div id="{{ $mount }}"></div>
        </main>
        <script type="application/json" id="{{ $propsId }}">{!! safe_json($props) !!}</script>
    </body>
</html>
