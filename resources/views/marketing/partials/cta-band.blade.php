{{--
    The closing call to action. One per page, at the bottom, always the same
    shape: a line, a button, and the price underneath in the quiet size.

    Same component on the home page, the trade page, how it works and contact,
    so the end of a page does not introduce a new visual idea in its last 200
    pixels. `$note` is optional and is the one line of reassurance that belongs
    beside the button rather than under it.

    The price and the trial length come from `config/billing.php` through
    `MarketingFigures`, which is why this partial takes no figures of its own.
--}}
<section class="cta-band">
    <h2>{{ $heading }}</h2>
    <a href="{{ $href ?? app_url('register') }}">{{ $label ?? 'Start free trial' }}</a>
    <p class="hero-price">
        <span class="fig">{{ $figures->monthlyBare() }}</span>/month,
        <span class="fig">{{ $figures->trialDays() }}</span>-day free trial. No card to start.
    </p>
    @isset($note)
        <p class="cta-note">{{ $note }}</p>
    @endisset
</section>
