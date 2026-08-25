<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:type" content="website">
    <link rel="canonical" href="{{ $url }}">
    @include('partials.head')
    @vite(['resources/css/app.css'])
    @if (config('services.plausible.domain'))
        <script defer data-domain="{{ config('services.plausible.domain') }}" src="https://plausible.io/js/script.js"></script>
    @endif
</head>
<body class="min-h-screen bg-paper font-sans text-14 text-ink antialiased">
    <header class="border-b border-rule">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
            <a
                href="{{ route('marketing.home') }}"
                class="font-display text-17 transition duration-fast ease-product hover:text-ink-2"
            >{{ config('app.name') }}</a>
            <nav class="flex items-center gap-6 text-13">
                <a
                    href="{{ route('marketing.pricing') }}"
                    class="text-ink-2 transition duration-fast ease-product hover:text-ink"
                >Pricing</a>
                <a
                    href="{{ route('marketing.dog-grooming') }}"
                    class="text-ink-2 transition duration-fast ease-product hover:text-ink"
                >Dog grooming</a>
                {{--
                    A returning owner needs a door too. Deliberately quieter than
                    the trial link: same size, muted until hover, no underline.

                    Kept identical for guests and signed-in visitors on purpose —
                    these pages are served with `cache.headers:public`, so a
                    shared cache could hand one visitor's header to another.
                --}}
                <a
                    href="{{ app_url('login') }}"
                    class="text-ink-2 transition duration-fast ease-product hover:text-ink"
                >Log in</a>
                <a
                    href="{{ app_url('register') }}"
                    class="underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >Start free trial</a>
            </nav>
        </div>
    </header>
    <main>
        @yield('content')
    </main>
    <footer class="mt-16 border-t border-rule">
        <div class="mx-auto flex max-w-5xl flex-wrap gap-6 px-4 py-8 text-13 text-ink-2">
            <a href="{{ route('marketing.about') }}" class="transition duration-fast ease-product hover:text-ink">About</a>
            <a href="{{ route('marketing.contact') }}" class="transition duration-fast ease-product hover:text-ink">Contact</a>
            <a href="{{ route('marketing.privacy') }}" class="transition duration-fast ease-product hover:text-ink">Privacy</a>
            <a href="{{ route('marketing.terms') }}" class="transition duration-fast ease-product hover:text-ink">Terms</a>
        </div>
    </footer>
</body>
</html>
