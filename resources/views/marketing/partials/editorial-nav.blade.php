{{--
    Editorial masthead. Identical for guests and signed-in visitors — these
    pages are `cache.headers:public`. Trial comes last. How it works and
    Pricing are the two product doors; the trade page lives in the footer.
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
