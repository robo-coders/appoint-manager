{{--
    The masthead. One file, included by the one layout, so every page on the
    surface carries the same header rather than a copy that has drifted.

    **Identical for guests and signed-in visitors, deliberately.** These pages
    are served with `cache.headers:public`, so a shared cache can hand one
    visitor's HTML to another — a header that varied by session would leak
    "Log in" or somebody's name across visitors. `MarketingNavTest` asserts byte
    equality between a guest's home page and a signed-in owner's.

    The trial link comes last so it reads as the primary door, and "Log in" is
    quieter than it: a returning owner knows what she is looking for, a stranger
    does not. Also asserted.

    **Nothing here names a trade.** The header is on every page of the site,
    including the ones for verticals we have not written yet, so the two product
    doors are How it works and Pricing. The vertical pages are reached from the
    footer, where their names come from the `verticals` table rather than from
    this file.
--}}
<header class="top">
    {{--
        The lockup, not the name set as type.

        `Vite::asset()` for the same reason the fonts in the shared head use it:
        the built filename carries a content hash, so a hardcoded
        `/build/assets/logo-<hash>.svg` would point at nothing the first time
        anybody rebuilt.

        There is one manifest for the whole build, and the entry this line looks
        up is put there by `Components/AppLogo.vue` importing the same file —
        this surface is Blade with no JavaScript entry of its own, so nothing on
        it imports anything. That is a real dependency between two surfaces and
        it is stated here rather than left to be discovered: if the Vue app ever
        stops importing `assets/logo.svg`, `Vite::asset()` throws and every page
        on this site 500s. `MarketingNavTest` gets every route on the surface,
        so it fails on the same commit rather than in front of a customer.

        Naming the four files as extra `input` entries in `vite.config.js` looks
        like the fix and is not: Vite treats an input as a module entry, and the
        manifest then maps `resources/js/assets/logo.svg` to a `.js` chunk.

        The alt text is the configured name, not a word typed here. The name
        *drawn in the artwork* no longer follows `product.name` — a logo's name
        never does — so the one string a screen reader gets is the one the rest
        of the product reads from config, and the two can be seen to disagree.
    --}}
    <a class="logo" href="{{ route('marketing.home') }}">
        <img src="{{ Vite::asset('resources/js/assets/logo.svg') }}" alt="{{ config('product.name') }}" width="163" height="40">
    </a>
    <nav class="navlinks" aria-label="Main">
        <a href="{{ route('marketing.how-it-works') }}" @class(['active' => request()->routeIs('marketing.how-it-works')])>How it works</a>
        <a href="{{ route('marketing.pricing') }}" @class(['active' => request()->routeIs('marketing.pricing')])>Pricing</a>
        <a class="pill-ghost" href="{{ app_url('login') }}">Log in</a>
        <a class="pill" href="{{ app_url('register') }}">Start free trial</a>
    </nav>
</header>
