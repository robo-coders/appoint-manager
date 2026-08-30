{{--
    The masthead: wordmark, three links and the way in.

    **Identical for guests and signed-in visitors, deliberately.** These pages
    are served with `cache.headers:public`, so a shared cache can hand one
    visitor's HTML to another — a header that varied by session would leak
    "Log in" or a name across visitors. `MarketingNavTest` asserts byte
    equality between a guest's home page and a signed-in owner's.

    The trial link comes last so it reads as the primary door, and "Log in" is
    quieter than it: a returning owner knows what she is looking for, a stranger
    does not. Also asserted.

    Pricing and the trade page carry `off-phone` and are in the footer at 375.
    See the note in `marketing.css` for why four links do not get a hamburger.
--}}
<header class="masthead">
    <div class="wrap">
        <a class="wordmark text-17 font-medium no-underline" href="{{ route('marketing.home') }}">{{ config('app.name') }}</a>
        <nav class="m-nav text-13" aria-label="Main">
            <a class="m-quiet off-phone" href="{{ route('marketing.pricing') }}">Pricing</a>
            <a class="m-quiet off-phone" href="{{ route('marketing.dog-grooming') }}">Dog grooming</a>
            <a class="m-quiet" href="{{ app_url('login') }}">Log in</a>
            <a class="m-link" href="{{ app_url('register') }}">Start free trial</a>
        </nav>
    </div>
</header>
