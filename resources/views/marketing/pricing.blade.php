@extends('marketing.layout')

{{--
    Pricing. Binding target: `.design/mockups/direction-a-pricing.html`.

    Every figure that is ours comes from `config('billing')` through
    `MarketingFigures`. The monthly/yearly toggle is plain JS reading those same
    values off data attributes, so a price change cannot leave the card stating
    one number and the script another.

    **The comparison column names nobody.** It used to name a competitor, on the
    argument that a generic label was vaguer. It was, and it was also a product
    we do not control publishing its prices on our site, kept accurate by
    nobody. The shape of the charge is the actual argument — somebody else's
    free tier charges your customer per booking, and we charge you once — and
    that argument does not need a name attached to it.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">Pricing</div>
            <h1>One price. Everything included.</h1>
            <p class="sub">No tiers to grow into, and no fee added to your customer's booking.</p>
        </div>

        <div
            class="price-wrap"
            id="price-root"
            data-monthly="{{ $figures->monthlyBare() }}"
            data-yearly="{{ $figures->yearlyBare() }}"
            data-saving="{{ $figures->yearlyLabel() }}"
        >
            <div class="interval">
                <div class="interval-group" role="group" aria-label="Billing interval">
                    <button type="button" id="bill-monthly" aria-pressed="true">Monthly</button>
                    <button type="button" id="bill-yearly" aria-pressed="false">Yearly</button>
                </div>
            </div>
            <div class="price-card">
                <div class="price-line">
                    <span class="amount" id="price-amount">{{ $figures->monthlyBare() }}</span>
                    <span class="period" id="price-period">/ month</span>
                </div>
                <div class="saving" id="price-saving" hidden>{{ $figures->yearlyLabel() }}</div>
                <div class="trial">{{ $figures->trialDays() }}-day free trial. No card required to start.</div>
                <a class="cta" href="{{ app_url('register') }}">Start your free trial</a>
                <ul class="included">
                    <li>Unlimited bookings, services and staff</li>
                    <li>Deposit taken at booking, into your own Stripe account</li>
                    <li>Waitlist that texts itself when a slot opens</li>
                    <li>{{ $figures->smsIncluded() }} texts a month included, then {{ $figures->smsTopupBare() }} per {{ $figures->smsTopupSize() }}</li>
                    <li>A booking page on your own link, for your customers</li>
                    <li>Reminders, rebooking prompts and a daily agenda by email</li>
                    <li>Cancel any time, from the billing screen</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-inner">
            <h2>Who actually pays</h2>
            <p class="lead">
                Some booking tools are free to you and take a fee from your customer at checkout.
                {{ config('product.name') }} charges you, once a month, and charges your customer
                nothing.
            </p>
            <div class="table-scroll">
                <table class="compare">
                    <tr>
                        <th></th>
                        <th>{{ config('product.name') }}</th>
                        <th>A typical competitor</th>
                    </tr>
                    <tr>
                        <td>What you pay</td>
                        <td class="you" id="compare-you">{{ $figures->monthlyBare() }} / month</td>
                        {{--
                            UNVERIFIED: a paid-tier range read off one competitor's public
                            price list at the time this page was written, with the product
                            unnamed. Nothing in this repository can check it and nobody is
                            told when it changes. It stays because the shape of the charge is
                            the argument, and it must keep this marker: a grep for UNVERIFIED
                            is how the figure and its caveat are found together.
                        --}}
                        <td class="them">
                            £0 on a free tier.<br>
                            Around £27 to £39 a month on the paid ones, depending on the tier
                            and whether you pay yearly.
                        </td>
                    </tr>
                    <tr>
                        <td>Fee added to your customer's booking</td>
                        <td class="you">None</td>
                        <td class="them">Yes, per booking, on the free tier</td>
                    </tr>
                    <tr>
                        <td>Deposit taken at booking</td>
                        <td class="you">Included</td>
                        <td class="them">Usually on a paid tier</td>
                    </tr>
                    <tr>
                        <td>Waitlist that refills a cancellation on its own</td>
                        <td class="you">Included</td>
                        <td class="them">Varies</td>
                    </tr>
                </table>
            </div>
            <p class="lead compare-note">
                The paid-tier figures above are read off a public price list and are not something
                this page can keep accurate for you. Check the current price before you compare.
            </p>
        </div>
    </section>

    <section class="faq">
        <div class="faq-inner">
            <h2>Questions</h2>
            <div class="q">
                <h3 class="question">What is the difference between monthly and yearly?</h3>
                <p class="answer">
                    Yearly is {{ $figures->yearlyBare() }}, which works out at about
                    {{ $figures->yearlyPerMonthBare() }} a month. Monthly is
                    {{ $figures->monthlyBare() }} and you can stop whenever you like. Nothing else
                    changes between them.
                </p>
            </div>
            <div class="q">
                <h3 class="question">What happens after the {{ $figures->smsIncluded() }} included texts?</h3>
                <p class="answer">
                    Top up another {{ $figures->smsTopupSize() }} for {{ $figures->smsTopupBare() }}.
                    Top-ups roll over rather than expiring at the end of the month. There is a
                    ceiling of {{ $figures->smsCeiling() }} texts per account per month so a
                    runaway loop can never hand you a bill you did not expect.
                </p>
            </div>
            <div class="q">
                <h3 class="question">Can I cancel during the trial?</h3>
                <p class="answer">
                    Yes, any time, and you are not charged. You give no card to start the trial, so
                    there is nothing to cancel a payment on.
                </p>
            </div>
            <div class="q">
                <h3 class="question">Do my customers pay anything to {{ config('product.name') }}?</h3>
                <p class="answer">
                    No. Deposits go to your own Stripe account, and
                    {{ config('product.name') }} only ever charges you the subscription. Stripe
                    takes its own card processing fee from the deposit, the same as it would on any
                    payment you take.
                </p>
            </div>
            <div class="q">
                <h3 class="question">What happens to my data if I stop paying?</h3>
                <p class="answer">
                    The account goes read-only rather than dark. Your customers' booking page keeps
                    working, you can still export everything, and nothing is deleted while the
                    account exists. The <a href="{{ route('marketing.terms') }}">terms</a> set out
                    how long that lasts.
                </p>
            </div>
        </div>
    </section>

    @include('marketing.partials.cta-band', [
        'heading' => 'One price, whatever your week looks like.',
    ])

    <script>
    (function () {
        var root = document.getElementById('price-root');
        if (!root) return;

        var monthly = document.getElementById('bill-monthly');
        var yearly = document.getElementById('bill-yearly');
        var amount = document.getElementById('price-amount');
        var period = document.getElementById('price-period');
        var saving = document.getElementById('price-saving');
        var compare = document.getElementById('compare-you');

        var monthlyPrice = root.getAttribute('data-monthly');
        var yearlyPrice = root.getAttribute('data-yearly');

        function show(isYearly) {
            monthly.setAttribute('aria-pressed', isYearly ? 'false' : 'true');
            yearly.setAttribute('aria-pressed', isYearly ? 'true' : 'false');
            amount.textContent = isYearly ? yearlyPrice : monthlyPrice;
            period.textContent = isYearly ? '/ year' : '/ month';
            saving.hidden = !isYearly;
            compare.textContent = isYearly ? yearlyPrice + ' / year' : monthlyPrice + ' / month';
        }

        monthly.addEventListener('click', function () { show(false); });
        yearly.addEventListener('click', function () { show(true); });
    })();
    </script>

@endsection
