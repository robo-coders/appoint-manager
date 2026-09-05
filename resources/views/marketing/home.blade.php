@extends('marketing.layout')

{{--
    Home. Binding target: `.design/mockups/Market-site/` — the approved
    marketing screenshots, which supersede `direction-a-editorial.html` for
    everything below the hero. The hero, masthead and footer are unchanged and
    still answer to the editorial direction they were built from.

    **Nothing on this page names a trade.** It is the page every vertical shares,
    so the diary in the hero shows customer names and appointment lengths rather
    than a grooming price list, and the trade-specific version of the same
    visual lives on `/dog-grooming`. The eyebrow used to read "For dog groomers
    in Scotland", which was the go-to-market message written into the one page
    that cannot carry it.

    The ledger / refill-sum homepage is archived at
    `.design/mockups/archived-ledger-homepage.blade.php`.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="hero-inner">
            <div class="eyebrow">Booking software for appointment businesses</div>
            <h1>The empty slot fills itself.</h1>
            <p class="sub">
                A cancellation goes straight to your waitlist by text, and the first person to
                reply gets it. A deposit taken at booking means a no-show stops costing you the
                hour.
            </p>
            <div class="hero-cta">
                <a class="pill" href="{{ app_url('register') }}">Start your free trial</a>
                <a class="pill-ghost" href="{{ route('marketing.how-it-works') }}">See how it works</a>
            </div>
            {{--
                The price, small, before the click rather than after it. Both
                figures come from `config/billing.php`; neither is typed here.
            --}}
            <p class="hero-price">
                <span class="fig">{{ $figures->monthlyBare() }}</span>/month,
                <span class="fig">{{ $figures->trialDays() }}</span>-day free trial. No card to start.
            </p>
        </div>

        <div class="visual">
            <div class="diary">
                <div class="diary-head">
                    <span class="day">Thursday, 4 September</span>
                    <span class="status">1 slot reclaimed today</span>
                </div>
                <div class="diary-body">
                    <div class="times">
                        <div class="trow">9:00</div>
                        <div class="trow">10:00</div>
                        <div class="trow">11:00</div>
                        <div class="trow">12:00</div>
                    </div>
                    <div class="slots">
                        <div class="srow"><div class="slot"><span class="name">Amy Fraser</span><span class="service">60 min</span></div></div>
                        <div class="srow"><div class="slot reclaimed"><span class="name">Ross Gilmour</span><span class="service">90 min</span><span class="tag">Waitlist · filled in 4 min</span></div></div>
                        <div class="srow"><div class="slot"><span class="name">Nadia Khan</span><span class="service">45 min</span></div></div>
                        <div class="srow"><div class="slot open">Open — waitlist notified</div></div>
                    </div>
                </div>
            </div>
            <p class="caption">Your diary on the day. Nothing to check, nobody to ring round.</p>
        </div>
    </section>

    <section class="stat-bar">
        <div class="stat-bar-inner">
            <div class="stat">
                <div class="fig"><span class="num">{{ $figures->offerMinutes() }}</span> min</div>
                <p>The window the first reply has to claim a cancelled slot.</p>
            </div>
            <div class="stat">
                <div class="fig"><span class="num">{{ $figures->offerBatch() }}</span></div>
                <p>People the offer reaches at once, in waitlist order.</p>
            </div>
            <div class="stat">
                <div class="fig"><span class="num">0</span></div>
                <p>Phone calls you make to fill a gap in the day.</p>
            </div>
        </div>
    </section>

    <section class="week">
        <div class="week-inner">
            <div class="eyebrow accent">The whole week</div>
            <h2>Every slot accounted for, without you counting.</h2>
            <p class="sub">
                Deposits taken, reminders sent, gaps refilled. What you see on a Monday morning is
                the week as it actually stands.
            </p>

            <div class="grid-card">
                <div class="grid-head">
                    <span class="wk">Week of 1 September</span>
                    <span class="grid-meta">
                        <span><span class="fig">38</span> booked</span>
                        <span><span class="fig">12</span> deposits held</span>
                        <span class="won"><span class="fig">3</span> reclaimed</span>
                    </span>
                </div>

                <div class="grid-days">
                    <div class="day-col">
                        <div class="day-name">Mon</div>
                        <div class="chip"><span class="t">09:00</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">11:30</span><span class="v">£25</span></div>
                        <div class="chip"><span class="t">14:15</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">16:00</span><span class="v">£35</span></div>
                    </div>
                    <div class="day-col">
                        <div class="day-name">Tue</div>
                        <div class="chip"><span class="t">09:30</span><span class="v">£45</span></div>
                        <div class="chip won"><span class="t">12:00</span><span class="v">reclaimed</span></div>
                        <div class="chip"><span class="t">15:00</span><span class="v">£25</span></div>
                        <div class="chip"><span class="t">16:45</span><span class="v">£45</span></div>
                    </div>
                    <div class="day-col">
                        <div class="day-name">Wed</div>
                        <div class="chip"><span class="t">09:00</span><span class="v">£35</span></div>
                        <div class="chip"><span class="t">10:45</span><span class="v">£45</span></div>
                        <div class="chip free"><span class="t">13:00</span><span class="v">open</span></div>
                        <div class="chip"><span class="t">15:30</span><span class="v">£45</span></div>
                    </div>
                    <div class="day-col">
                        <div class="day-name">Thu</div>
                        <div class="chip"><span class="t">09:00</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">10:15</span><span class="v">£45</span></div>
                        <div class="chip won"><span class="t">13:00</span><span class="v">reclaimed</span></div>
                        <div class="chip"><span class="t">15:30</span><span class="v">£25</span></div>
                    </div>
                    <div class="day-col">
                        <div class="day-name">Fri</div>
                        <div class="chip"><span class="t">08:45</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">10:30</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">12:15</span><span class="v">£35</span></div>
                        <div class="chip"><span class="t">14:00</span><span class="v">£45</span></div>
                    </div>
                    <div class="day-col">
                        <div class="day-name">Sat</div>
                        <div class="chip"><span class="t">09:00</span><span class="v">£45</span></div>
                        <div class="chip won"><span class="t">11:00</span><span class="v">reclaimed</span></div>
                        <div class="chip"><span class="t">13:30</span><span class="v">£45</span></div>
                        <div class="chip"><span class="t">15:15</span><span class="v">£25</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-inner">
            <h2>What changes on day one.</h2>
            <div class="rows">
                <div class="row">
                    <div class="label">01 — At booking</div>
                    <div class="body">A deposit is held on the card. <b>If they don't turn up, you are not the one who loses the hour.</b></div>
                </div>
                <div class="row">
                    <div class="label">02 — On cancellation</div>
                    <div class="body">The slot goes to your waitlist by text, straight away. <b>You don't write it and you don't send it.</b></div>
                </div>
                <div class="row">
                    <div class="label">03 — First to reply</div>
                    <div class="body">Takes the slot there and then. <b>Whoever is quickest gets it. No group chat, no ringing round.</b></div>
                </div>
            </div>
        </div>
    </section>

    <section class="sendable">
        <div class="sendable-inner">
            <div class="sendable-copy">
                <div class="eyebrow accent">Your booking page</div>
                <h2>A page you can send, in your own colour.</h2>
                <p>
                    Customers pick a service, pick a time, and pay the deposit. If nothing suits,
                    they join the waitlist in one tap — which is how the next cancellation fills
                    itself.
                </p>
                <a class="pill-ghost" href="{{ route('marketing.dog-grooming') }}">See an example page</a>
            </div>

            <div class="booking-card">
                <div class="bc-head">
                    <div class="bc-name">Bruce &amp; Bonnie</div>
                    <div class="bc-trade">Leith, Edinburgh</div>
                </div>
                <div class="bc-services">
                    <div class="bc-service">
                        <span class="s">Full session</span>
                        <span class="fig">60 min · £45</span>
                    </div>
                    <div class="bc-service picked">
                        <span class="s">Short session</span>
                        <span class="fig">45 min · £25</span>
                    </div>
                </div>
                <div class="bc-times">
                    <span class="bc-time">10:15</span>
                    <span class="bc-time">13:00</span>
                    <span class="bc-time picked">15:30</span>
                </div>
                <div class="bc-total">
                    <span>Deposit today</span>
                    <span class="fig">£10.00</span>
                </div>
            </div>
        </div>
    </section>

    {{--
        The questions, from `App\Support\MarketingFaq::home()`.

        The home page had none, which left the eight questions a stranger
        actually arrives with — what is it, what does it cost, is there a trial,
        who is it for — answerable only by reading three other pages. They are
        also the questions an answer engine is asked on this product's behalf,
        and the same array is published as `FAQPage` JSON-LD in the head, so
        there is one copy of each answer rather than a page and a markup block
        that drift.

        `.faq` and `.q` are the pricing page's existing styles, unchanged and
        page-agnostic — this adds a section, not a look.
    --}}
    @include('marketing.partials.faq', ['faq' => $faq, 'heading' => 'Common questions'])

    @include('marketing.partials.cta-band', [
        'heading' => 'Put your own week in it.',
        'note' => 'Set up your services, open your booking page, see what a cancellation does.',
    ])

@endsection
