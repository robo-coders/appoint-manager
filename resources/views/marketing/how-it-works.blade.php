@extends('marketing.editorial')

{{--
    Editorial how-it-works. Binding target:
    `.design/mockups/direction-a-how-it-works.html`.

    Deposit figures are the seeded list, not invented.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">How it works</div>
            <h1>Three steps. No manual work.</h1>
            <p class="sub">From booking to refill, {{ config('product.name') }} handles the part that used to cost you money.</p>
        </div>
    </section>

    <div class="steps">
        <div class="step">
            <div>
                <div class="index">01</div>
                <h2>A deposit is held at booking</h2>
                <p>Your customer pays a deposit the moment they book, straight to your Stripe account. If they don't show, you're not out of pocket.</p>
            </div>
            <div class="art">
                <div class="card"><span>Bella — Full groom</span><b>{{ $figures->depositBare() }} held</b></div>
                <div class="card"><span>Coco — Bath &amp; tidy</span><b>{{ $figures->depositBare() }} held</b></div>
            </div>
        </div>

        <div class="step">
            <div>
                <div class="index">02</div>
                <h2>A cancellation texts your waitlist</h2>
                <p>The moment a slot opens up, everyone on your waitlist for that day gets a text. You don't write it, you don't send it.</p>
            </div>
            <div class="art">
                <div class="msg">Max's 10am opened up — reply YES to take it</div>
            </div>
        </div>

        <div class="step">
            <div>
                <div class="index">03</div>
                <h2>First to reply gets the slot</h2>
                <p>Whoever answers first is booked automatically. No group chats, no double-booking, no calls back and forth.</p>
            </div>
            <div class="art">
                <div class="card highlight"><span>Max's 10am — Full groom</span><span class="tag">Filled · 4 min</span></div>
            </div>
        </div>
    </div>

    <section class="cta-band">
        <h2>Try it with your own diary.</h2>
        <a href="{{ app_url('register') }}">Start your free trial</a>
    </section>

@endsection
