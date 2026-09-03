@extends('marketing.layout')

{{--
    How it works. Binding target: `.design/mockups/direction-a-how-it-works.html`.

    Three steps, and the third one is the one nobody else does: the follow-up
    text to everybody who was not first. The quoted message bodies are the real
    strings from `App\Services\Notifications\Notifier`, not copywriting, and
    `MarketingNavTest` asserts them against that class so a rewrite there shows
    up as a failing test rather than as a stale page.

    Vertical-neutral, like everything except `/dog-grooming`: the example rows
    are a customer name and a time, because the services a business sells are
    the business's own.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">How it works</div>
            <h1>Three steps. No manual work.</h1>
            <p class="sub">From the booking to the refill, {{ config('product.name') }} does the part that used to cost you money.</p>
        </div>
    </section>

    <div class="steps">
        <div class="step">
            <div>
                <div class="index">01</div>
                <h2>A deposit is held at booking</h2>
                <p>
                    Your customer pays a deposit when they book, straight into your own Stripe
                    account. You set the amount per service, and you can set it to nothing on the
                    short ones. If they don't turn up, you are not out of pocket for the hour.
                </p>
            </div>
            <div class="art">
                <div class="card"><span>Amy Fraser · Thu 10:00</span><b>Deposit held</b></div>
                <div class="card"><span>Nadia Khan · Thu 11:00</span><b>Deposit held</b></div>
            </div>
        </div>

        <div class="step">
            <div>
                <div class="index">02</div>
                <h2>A cancellation texts your waitlist</h2>
                <p>
                    The moment the slot opens, {{ $figures->offerBatch() }} people on your waitlist
                    get a text with a link. Only the ones who wanted that service, on a day they
                    said they could do. You don't write the message and you don't send it.
                </p>
            </div>
            <div class="art">
                {{-- Word for word what the Notifier sends, after the salon's own name. --}}
                <div class="msg"><span class="quiet">the salon's name</span>: a slot is free. Claim: <span class="quiet">…/offer/9f2c</span></div>
            </div>
        </div>

        <div class="step">
            <div>
                <div class="index">03</div>
                <h2>First to reply gets the slot</h2>
                <p>
                    Whoever opens the link first is booked, and everybody else is told the slot
                    went. Nobody replies to a text and nobody rings you back. If the first
                    {{ $figures->offerBatch() }} go quiet for
                    {{ $figures->offerMinutes() }} minutes, the next {{ $figures->offerBatch() }}
                    get it.
                </p>
            </div>
            <div class="art">
                <div class="card highlight"><span>Thu 10:00 · Ross Gilmour</span><span class="tag">Filled · 4 min</span></div>
                <div class="msg msg-later"><span class="quiet">the salon's name</span>: that slot was taken. We will text if another opens.</div>
            </div>
        </div>
    </div>

    @include('marketing.partials.cta-band', [
        'heading' => 'Try it with your own diary.',
        'note' => 'Import your next fortnight, open your booking page, cancel something and watch.',
    ])

@endsection
