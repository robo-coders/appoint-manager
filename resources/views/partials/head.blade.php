{{--
    Shared <head> assets: icons and typefaces.

    **The typefaces are self-hosted.** Geist and Geist Mono live in
    resources/fonts/ as three woff2 files and are declared by the @font-face
    block at the top of resources/css/base.css, which both bundles import. There
    is no font host any more: no `preconnect`, no third-party stylesheet, and
    `font-src` in App\Http\Middleware\SecurityHeaders no longer names one.

    Both sans weights are preloaded, and only those two. They are what the *first*
    paint needs — body text at 400 and every heading, button and active nav item
    at 500 — so they are the two the browser would otherwise discover only after
    it had parsed the stylesheet that names them. Geist Mono is deliberately not
    preloaded: it sets figures, which are never the first thing on screen, and a
    third preload competes with the two that are.

    `Vite::asset()` rather than a hardcoded path, because the built filenames
    carry a content hash. It resolves through the manifest in production and
    through the dev server while `npm run dev` is running, so the preload can
    never point at a file that has been rebuilt out from under it.
--}}
<link rel="icon" href="/icons/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="/icons/icon-16.png" sizes="16x16" type="image/png">
<link rel="apple-touch-icon" href="/icons/icon-180.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#FCFBF9"> {{-- design-tokens-ignore: an HTML meta value cannot hold a CSS variable; mirrors --paper --}}

{{--
    `crossorigin` is not optional here even though these are same-origin in
    production. Fonts are always fetched in CORS mode, so a preload without it
    is a *different* request from the one @font-face makes, and the browser
    downloads each file twice — which makes the preload slower than no preload.
--}}
<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ Vite::asset('resources/fonts/Geist-Regular.woff2') }}">
<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ Vite::asset('resources/fonts/Geist-Medium.woff2') }}">
