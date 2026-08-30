@extends('marketing.layout')

{{--
    Pricing.

    One price, stated once: £39 a month, read from `config('billing')` rather
    than typed. No tiers, no volume floor, no annual discount, no "from £39".

    **The annual price still exists in the product and is deliberately not sold
    here.** `config('billing.yearly_price_pence')` is 39000 and `STRIPE_PRICE_YEARLY`
    is still wired, so a salon can be on an annual subscription; this page simply
    does not offer one, because a second price on a pricing page is a decision
    the reader has to make before she has decided the first thing. Recorded in
    DECISIONS.md so the gap between the config and the page is written down
    rather than discovered.

    The ledger is the evidence column of the hero. It came off the home page,
    where it was losing, and it is reframed here: not a cost comparison but the
    answer to "why do you charge me when they are free?". See
    `partials/ledger.blade.php` for the reasoning and for the one unverified
    figure on this surface.
--}}

@section('content')

    <section class="hero">
        <div class="wrap split">
            <div class="claim">
                <h1 class="text-34">Free is not free. It is billed to your clients.</h1>
                <div class="price mt-8">
                    <span class="amt">{{ $figures->monthlyBare() }}</span>
                    <span class="text-17 text-ink-2">a month</span>
                </div>
                <p class="lede">
                    {{ $figures->trialDays() }} days free. No card to start. One plan, and it is
                    this one.
                </p>
                @include('marketing.partials.cta', ['label' => 'Start free trial'])
            </div>

            <div class="evidence">
                @include('marketing.partials.ledger')
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">One plan, and everything is in it</h2>
                <p class="lede">
                    There is no tier where deposits are switched off, and nothing here is metered.
                </p>
            </div>
            <div class="evidence">
                <dl class="facts">
                    <div>
                        <dt>Every appointment, every client, every dog.</dt>
                        <dd>
                            No cap and no per-booking charge, to you or to them. Take twenty
                            appointments a month or eight hundred; it is the same
                            {{ $figures->monthly()->formatted() }}.
                        </dd>
                    </div>
                    <div>
                        <dt>Deposits, the waitlist, reminders and your booking page.</dt>
                        <dd>
                            Not add-ons. The waitlist that refills a cancelled hour is the reason
                            this product exists, so putting it behind a higher tier would be
                            selling the empty version.
                        </dd>
                    </div>
                    <div>
                        <dt>Text messages are included.</dt>
                        <dd>
                            Confirmations, reminders and waitlist offers all go out by SMS and we
                            do not bill you per message. There is no bundle to run out of
                            mid-Saturday.
                        </dd>
                    </div>
                    <div>
                        <dt>Card processing is Stripe's, at Stripe's rate.</dt>
                        <dd>
                            It is charged to your own Stripe account by Stripe, and we do not add
                            anything to it. We never touch your clients' money.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- The confidence move: publish the exit, including what she cannot take. --}}
    <section class="sec sec-airy">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">How you leave</h2>
                <p class="lede">
                    We would rather write this down now than have you find it out later.
                </p>
            </div>
            <div class="evidence">
                <div class="prose text-15">
                    <p>
                        There is no contract and no notice period. It is a monthly subscription and
                        you end it in settings, in one place, without emailing anybody. Your client
                        list and your appointment history export to a spreadsheet on the way out.
                        The Stripe account leaves with you, because it was always yours.
                    </p>
                    <p>
                        What you cannot take is the booking page itself. That lives here, and if you
                        go, the link stops working — so keep your own note of where you sent your
                        clients. Everything you brought, you keep.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">What people ask about the price</h2>
                <p class="lede">Including the one where the free plan wins.</p>
            </div>
            <div class="evidence">
                <dl class="qa">
                    <div>
                        <dt>Is there a cheaper plan for a quiet book?</dt>
                        <dd>
                            No, and there is not going to be. A cheaper tier with the waitlist taken
                            out would be the version that does not do the thing we built, sold to
                            the people who need it most. If
                            {{ $figures->monthly()->formatted() }} a month is more than a refilled
                            cancellation is worth to you, the free plans are genuinely the better
                            deal and we will not pretend otherwise.
                        </dd>
                    </div>
                    <div>
                        <dt>What if they stop charging my clients?</dt>
                        <dd>
                            Then the bill above stops being an argument, and you would be choosing
                            on the rest of it: your own booking page, your own Stripe account, your
                            own client list, and nobody's marketplace standing between you and the
                            people who already know you. We would rather you knew this particular
                            argument has an expiry date on it.
                        </dd>
                    </div>
                    <div>
                        <dt>Do I pay more as I get busier?</dt>
                        <dd>
                            No. There is no volume floor and no volume ceiling — no appointment
                            count anywhere in what you pay us.
                        </dd>
                    </div>
                    <div>
                        <dt>What happens at the end of the trial?</dt>
                        <dd>
                            You are asked for a card. Nothing is charged before then and nothing is
                            charged if you walk away — there is no card on file to charge. If you do
                            nothing, the admin app goes read-only and your clients' booking page
                            keeps working.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

@endsection
