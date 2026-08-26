{{--
    Shared <head> assets: icons and typefaces.

    fonts.bunny.net is the only font host allowed by the CSP in
    App\Http\Middleware\SecurityHeaders. Söhne and Inter Display are the
    preferred display faces but neither is licensable through a free
    self-hosted CDN.

    All three faces named by --font-sans and --font-mono are actually loaded.
    Inter was previously named as the fallback and never requested, so a browser
    that failed to fetch Geist fell all the way through to the system face and
    the type looked nothing like the design.

    Only the two weights the design system uses (400 and 500) are requested.
--}}
<link rel="icon" href="/icons/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="/icons/icon-16.png" sizes="16x16" type="image/png">
<link rel="apple-touch-icon" href="/icons/icon-180.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#FCFBF9"> {{-- design-tokens-ignore: an HTML meta value cannot hold a CSS variable; mirrors --paper --}}

<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link
    rel="stylesheet"
    href="https://fonts.bunny.net/css?family=geist:400,500|geist-mono:400,500|inter:400,500&display=swap"
>
