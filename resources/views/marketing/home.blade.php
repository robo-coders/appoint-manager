@extends('marketing.editorial')

{{--
    Editorial home. Binding target: `.design/mockups/direction-a-editorial.html`.

    The ledger / refill-sum homepage is archived at
    `.design/mockups/archived-ledger-homepage.blade.php`.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="hero-inner">
            <div class="eyebrow">For dog groomers in Scotland</div>
            <h1>The empty slot fills itself.</h1>
            <p class="sub">A cancellation goes straight to your waitlist by text — first to reply gets it. A deposit at booking means no-shows stop costing you.</p>
            <div class="hero-cta">
                <a class="pill" href="{{ app_url('register') }}">Start your free trial</a>
                <a class="pill-ghost" href="{{ route('marketing.how-it-works') }}">See it in 90 seconds</a>
            </div>
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
                        <div class="srow"><div class="slot"><span class="name">Bella</span><span class="service">Full groom</span></div></div>
                        <div class="srow"><div class="slot reclaimed"><span class="name">Max</span><span class="service">Full groom</span><span class="tag">Waitlist · filled in 4 min</span></div></div>
                        <div class="srow"><div class="slot"><span class="name">Coco</span><span class="service">Bath &amp; tidy</span></div></div>
                        <div class="srow"><div class="slot open">Open — waitlist notified</div></div>
                    </div>
                </div>
            </div>
            <p class="caption">Your actual diary. No app to check, no calls to make.</p>
        </div>
    </section>

    <section class="section">
        <div class="section-inner">
            <h2>Three things happen the moment someone books.</h2>
            <div class="rows">
                <div class="row">
                    <div class="label">01 — At booking</div>
                    <div class="body">A card is held for a deposit. <b>If they don't show, you're not the one who loses.</b></div>
                </div>
                <div class="row">
                    <div class="label">02 — On cancellation</div>
                    <div class="body">The slot goes to your waitlist automatically. <b>You don't send a single text yourself.</b></div>
                </div>
                <div class="row">
                    <div class="label">03 — First to reply</div>
                    <div class="body">Books the slot instantly. <b>Whoever's fastest gets it — no group chat, no back and forth.</b></div>
                </div>
            </div>
        </div>
    </section>

@endsection
