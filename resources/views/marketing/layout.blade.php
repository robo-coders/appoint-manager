{{--
    The marketing shell. One layout for the whole surface.

    Binding target: `.design/mockups/direction-a-editorial.html` and its two
    siblings, `direction-a-pricing.html` and `direction-a-how-it-works.html`.
    Type scale, canvas, ink and the single clay accent come from those files by
    way of `resources/css/marketing-editorial-tokens.css`.

    **There used to be two of these.** Home, pricing and how-it-works were on
    the editorial shell and the other five pages were still on a ledger-style
    one with its own stylesheet and its own masthead and footer — two type
    scales, two footers and two sets of tokens on one domain. Everything is on
    this one now, and `marketing.css`, `tailwind.marketing.config.js` and the
    ledger partials are gone rather than left to rot.

    `data-surface="marketing"` stays: the caches, the CSP and the nav tests key
    off it. `data-page` switches the type scale and the hero padding per page,
    which is the only thing that varies between them.

    The body is a flex column with `<main>` taking the slack, so /about — which
    is 400px of content — does not leave the footer floating halfway up the
    viewport with bare paper underneath it.
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('product.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:site_name" content="{{ config('product.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_GB">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if (($noindex ?? false) === true)
        <meta name="robots" content="noindex">
    @endif
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

    @include('marketing.partials.nav')

    <main id="main">
        @yield('content')
    </main>

    @include('marketing.partials.footer')
</body>
</html>
