{{--
    The footer. One file, included by the one layout.

    `MarketingNavTest` walks every link in here on every page, because a 404
    from the marketing footer is the bug you find by accident in front of a
    customer.

    **The logo slot is empty and says so.** There is no wordmark or logo asset
    yet. A placeholder that looks like a logo is worse than a marked space,
    because somebody ships it — so it is a dashed hairline box with the word
    "Logo" in it, and it comes out in one commit when there is a real mark.

    **The trade pages are listed by their own labels.** "Dog grooming" is the
    `label` on the groomer row in `verticals`, read through
    `App\Support\MarketingNav`, not typed here. The footer is on every page of
    the site and has to stay true when the second vertical arrives.
--}}
@php($verticals = App\Support\MarketingNav::verticalPages())

<footer class="foot">
    <div class="foot-inner">
        <div class="foot-brand">
            <div class="foot-mark">
                <span class="logo-slot" title="No logo artwork yet">Logo</span>
                <a class="foot-wordmark" href="{{ route('marketing.home') }}">{{ config('product.name') }}</a>
            </div>
            <p class="foot-blurb">
                Booking, deposits and a waitlist that refills its own cancellations.
                Built for small appointment businesses.
            </p>
        </div>

        <nav class="foot-nav" aria-label="Footer">
            <div>
                <h2>Product</h2>
                <ul>
                    <li><a href="{{ route('marketing.how-it-works') }}">How it works</a></li>
                    <li><a href="{{ route('marketing.pricing') }}">Pricing</a></li>
                    @foreach ($verticals as $vertical)
                        <li><a href="{{ $vertical['href'] }}">{{ $vertical['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h2>Company</h2>
                <ul>
                    <li><a href="{{ route('marketing.about') }}">About</a></li>
                    <li><a href="{{ route('marketing.contact') }}">Contact</a></li>
                    <li><a href="{{ app_url('login') }}">Log in</a></li>
                    <li><a href="{{ app_url('register') }}">Start free trial</a></li>
                </ul>
            </div>
            <div>
                <h2>Legal</h2>
                <ul>
                    <li><a href="{{ route('marketing.privacy') }}">Privacy</a></li>
                    <li><a href="{{ route('marketing.terms') }}">Terms</a></li>
                    <li><a href="mailto:{{ $figures->contactEmail() }}">{{ $figures->contactEmail() }}</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="foot-base">
        {{-- The year is read, not typed. A footer that says 2026 in 2027 is a dead site. --}}
        <span>&copy; {{ now()->year }} {{ config('product.name') }}</span>
        <span>Built in East Kilbride, Scotland</span>
    </div>
</footer>
