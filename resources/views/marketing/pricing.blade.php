@extends('marketing.editorial')

{{--
    Editorial pricing. Binding target: `.design/mockups/direction-a-pricing.html`.

    Figures come from `config('billing')` via MarketingFigures. The yearly
    toggle is plain JS reading those same values off data attributes, so a
    price change cannot leave the card stating one number and the script
    another.

    The Tuft column is the published list as of the mockup. It is named
    because a generic "typical competitor" row was the thing we replaced.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">Pricing</div>
            <h1>One price. Everything included.</h1>
            <p class="sub">No tiers to grow into, no fee added to your customer's booking.</p>
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
                <div class="trial">{{ $figures->trialDays() }}-day free trial · no card required to start</div>
                <a class="cta" href="{{ app_url('register') }}">Start your free trial</a>
                <ul class="included">
                    <li>Unlimited bookings and staff</li>
                    <li>Deposit capture on every booking</li>
                    <li>Automated waitlist texting</li>
                    <li>{{ $figures->smsIncluded() }} SMS included, {{ $figures->smsTopupBare() }} per {{ $figures->smsTopupSize() }} more</li>
                    <li>Public booking page for your business</li>
                    <li>Cancel any time</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-inner">
            <h2>Who actually pays</h2>
            <p class="lead">Some booking tools are free to you and charge your customer at checkout. {{ config('product.name') }} charges you, once, and nothing else.</p>
            <table class="compare">
                <tr>
                    <th></th>
                    <th>{{ config('product.name') }}</th>
                    <th>Tuft</th>
                </tr>
                <tr>
                    <td>What you pay</td>
                    <td class="you" id="compare-you">{{ $figures->monthlyBare() }} / month</td>
                    <td class="them">
                        Essential £0<br>
                        Pro £27.50/mo annual or £32.99/mo rolling<br>
                        Pro Multi £38.50/mo annual
                    </td>
                </tr>
                <tr>
                    <td>Fee added to customer's booking</td>
                    <td class="you">None</td>
                    <td class="them">Yes, per booking</td>
                </tr>
                <tr>
                    <td>Deposit capture</td>
                    <td class="you">Included</td>
                    <td class="them">Included</td>
                </tr>
                <tr>
                    <td>Waitlist auto-fill</td>
                    <td class="you">Included</td>
                    <td class="them">Included</td>
                </tr>
            </table>
        </div>
    </section>

    <section class="faq">
        <div class="faq-inner">
            <h2>Questions</h2>
            <div class="q">
                <h3 class="question">What happens after the {{ $figures->smsIncluded() }} included texts?</h3>
                <p class="answer">Top up with another {{ $figures->smsTopupSize() }} for {{ $figures->smsTopupBare() }}. There's a hard ceiling per account so you're never billed for a runaway usage spike.</p>
            </div>
            <div class="q">
                <h3 class="question">Can I cancel during the trial?</h3>
                <p class="answer">Yes, any time, no charge. Your data stays available if you come back within {{ $figures->trialDays() }} days.</p>
            </div>
            <div class="q">
                <h3 class="question">Do my customers pay anything to {{ config('product.name') }} directly?</h3>
                <p class="answer">No. Deposits go to your account via Stripe. {{ config('product.name') }} only charges you the monthly fee.</p>
            </div>
        </div>
    </section>

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

        function setInterval(isYearly) {
            monthly.setAttribute('aria-pressed', isYearly ? 'false' : 'true');
            yearly.setAttribute('aria-pressed', isYearly ? 'true' : 'false');
            amount.textContent = isYearly ? yearlyPrice : monthlyPrice;
            period.textContent = isYearly ? '/ year' : '/ month';
            saving.hidden = !isYearly;
            compare.textContent = isYearly ? yearlyPrice + ' / year' : monthlyPrice + ' / month';
        }

        monthly.addEventListener('click', function () { setInterval(false); });
        yearly.addEventListener('click', function () { setInterval(true); });
    })();
    </script>

@endsection
