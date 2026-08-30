{{--
    The marketing shell.

    Converted from `.design/mockups/directions/direction-a-ledger.html`. What
    the mockup holds in one file, this splits three ways: this shell, the
    masthead and the footer. The split follows the seam the mockup itself draws
    — everything outside `<main>` is chrome that every page carries identically,
    and everything inside it is the page's argument.

    `data-surface="marketing"` is the whole reason `--page`, `--gutter` and
    `--arg` could move into `tokens.css`. It is set here, once, on the surface's
    root, which is the same rule `data-density` follows.

    No `data-density`. The three densities exist so one component library can
    serve three surfaces at three sizes; this surface uses none of that library,
    so setting one would be a value nothing reads.

    The body is a full-height flex column with `<main>` taking the slack, which
    is what `public-shell.blade.php` does and for the same reason: /about is
    370px of content, and without it the footer sits where the content stops and
    350px of bare paper hangs underneath it. Verified at five widths — the four
    prose pages were the ones that showed it.
--}}
<!DOCTYPE html>
<html lang="en-GB">
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
    @vite(['resources/css/marketing.css'])
    @if (config('services.plausible.domain'))
        <script defer data-domain="{{ config('services.plausible.domain') }}" src="https://plausible.io/js/script.js"></script>
    @endif
</head>
<body data-surface="marketing" class="flex min-h-screen flex-col bg-paper font-sans text-15 text-ink antialiased">
    <a class="skip-link" href="#main">Skip to content</a>

    @include('marketing.partials.masthead')

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    @include('marketing.partials.footer')
</body>
</html>
