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
    <a class="logo" href="{{ route('marketing.home') }}">{{ config('product.name') }}</a>
    <nav class="navlinks" aria-label="Main">
        <a href="{{ route('marketing.how-it-works') }}" @class(['active' => request()->routeIs('marketing.how-it-works')])>How it works</a>
        <a href="{{ route('marketing.pricing') }}" @class(['active' => request()->routeIs('marketing.pricing')])>Pricing</a>
        <a class="pill-ghost" href="{{ app_url('login') }}">Log in</a>
        <a class="pill" href="{{ app_url('register') }}">Start free trial</a>
    </nav>
</header>
