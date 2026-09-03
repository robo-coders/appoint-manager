{{--
    Editorial marketing shell.

    Converted from `.design/mockups/direction-a-editorial.html` and its sibling
    pages. A different layout from `marketing.layout` — that shell still serves
    the remaining ledger-style pages (trade, about, legal). Home, pricing and
    how-it-works share this one so the type, canvas and nav stay identical.

    `data-surface="marketing"` stays: caches, CSP and the nav tests key off it.
    `data-page` switches the type scale between the three mockups.
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('product.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:type" content="website">
    <link rel="canonical" href="{{ $url }}">
    @include('partials.head')
    <meta name="theme-color" content="#FBF9F5"> {{-- design-tokens-ignore: editorial canvas, not --paper; HTML meta cannot hold a CSS variable --}}
    @vite(['resources/css/marketing-editorial.css'])
    @if (config('services.plausible.domain'))
        <script defer data-domain="{{ config('services.plausible.domain') }}" src="https://plausible.io/js/script.js"></script>
    @endif
</head>
<body data-surface="marketing" data-page="{{ $page }}">
    <a class="skip-link" href="#main">Skip to content</a>

    @include('marketing.partials.editorial-nav')

    <main id="main">
        @yield('content')
    </main>

    @include('marketing.partials.editorial-footer')
</body>
</html>
