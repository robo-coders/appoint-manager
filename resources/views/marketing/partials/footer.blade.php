{{--
    The footer. Pricing and the trade page are in the masthead at every width
    as well; they stay here because About, Contact, Privacy and Terms have
    nowhere else to live. `MarketingNavTest` walks every link in here on every
    page, because a 404 from the marketing footer is the bug you find by
    accident in front of a customer.
--}}
<footer class="m-foot text-13 text-ink-2">
    <div class="wrap">
        <span>{{ config('product.name') }}</span>
        <nav aria-label="Footer">
            <a class="m-quiet" href="{{ route('marketing.how-it-works') }}">How it works</a>
            <a class="m-quiet" href="{{ route('marketing.pricing') }}">Pricing</a>
            <a class="m-quiet" href="{{ route('marketing.dog-grooming') }}">Dog grooming</a>
            <a class="m-quiet" href="{{ route('marketing.about') }}">About</a>
            <a class="m-quiet" href="{{ route('marketing.contact') }}">Contact</a>
            <a class="m-quiet" href="{{ route('marketing.privacy') }}">Privacy</a>
            <a class="m-quiet" href="{{ route('marketing.terms') }}">Terms</a>
        </nav>
    </div>
</footer>
