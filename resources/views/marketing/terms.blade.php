@extends('marketing.layout')

{{--
    Terms.

    Every figure is read from `config('billing')` through `MarketingFigures`,
    the same as the pricing page, so a price change cannot leave the terms
    stating one number and the checkout charging another. That is not
    pedantry: the terms are the document a chargeback dispute is read against.

    The deposit section is the one that matters most and it is deliberately
    blunt. We move the money; the salon sets the policy and answers for it. A
    salon that reads this and thinks we will handle a refund argument with their
    customer has read it wrong, and that is our fault to prevent here.

    **Not reviewed by a solicitor, and the page says so at the top.**
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">Terms</div>
            <h1>The agreement, in plain words.</h1>
            <p class="sub">
                What you are paying for, what we are responsible for, and what stays yours.
            </p>
        </div>
    </section>

    <div class="doc">
        <div class="doc-caveat">
            <p>
                <b>These terms should be reviewed by a solicitor before the product is sold at
                scale.</b> They have not been. They are an honest statement of how
                {{ config('product.name') }} is intended to work, written by the person who built
                it, and they are not legal sign-off.
            </p>
        </div>

        {{-- Bumped by hand when the terms change. A date that moves on its own is not a version. --}}
        <p class="doc-updated">Last updated 3 September 2026</p>

        <section>
            <h2>Who this is between</h2>
            <p>
                It is between {{ config('product.name') }} and the business that holds the
                account. Not between us and your customers. Your customers deal with you, and
                these terms give them no rights against us and us none against them.
            </p>
        </section>

        <section>
            <h2>The subscription</h2>
            <ul>
                <li>
                    <b>{{ $figures->monthlyBare() }} a month, or {{ $figures->yearlyBare() }} a
                    year.</b> One tier. Unlimited bookings, services and staff on either.
                </li>
                <li>
                    <b>A {{ $figures->trialDays() }}-day free trial.</b> No card is taken to start
                    it. If you do nothing at the end of it, nothing is charged.
                </li>
                <li>
                    <b>It renews until you stop it.</b> Monthly on the same day each month, yearly
                    on the same day each year, unless you cancel first.
                </li>
                <li>
                    <b>Cancel any time, from the billing screen.</b> You keep the rest of the
                    period you have already paid for. We do not refund part of a period, and we do
                    not make you telephone anybody to leave.
                </li>
                <li>
                    <b>A price change is told to you first</b>, by email, before it applies to
                    your account. It never applies to a period you have already paid for.
                </li>
            </ul>
        </section>

        <section>
            <h2>If a payment fails, and what happens to your data</h2>
            <p>
                A failed payment does not switch your account off. You get
                {{ config('billing.dunning_days') }} days while we retry, and we tell you it has
                happened.
            </p>
            <p>
                After that the account becomes <b>read-only rather than dark</b>. You and your
                staff can still sign in, still see the diary and still export everything.
                <b>Your customers' booking page keeps working</b>, because a salon that has
                fallen behind on a software bill should not have its Saturday cancelled by us.
                What stops is making changes in the admin app.
            </p>
            <p>
                Cancelling stops the billing. It does not delete the account, so you can come back
                to it or export from it later. Ask us to delete it and we will, within one month.
                Nothing is deleted without you asking. What we cannot delete is billing and tax
                records, which we are required to keep for six years.
            </p>
        </section>

        <section>
            <h2>Deposits, and whose policy they are</h2>
            <p>
                <b>{{ config('product.name') }} moves the money. You set the policy and you answer
                for it.</b> That is the whole of this section and the rest of it is detail.
            </p>
            <ul>
                <li>
                    Deposits are taken through Stripe into <b>your own Stripe account</b>. The
                    money is yours from the moment it clears. It does not pass through us and we
                    do not hold it.
                </li>
                <li>
                    <b>You decide</b> which services take a deposit, how much, and what happens
                    when somebody cancels late or does not turn up. You can set it to nothing.
                </li>
                <li>
                    <b>That policy is an agreement between you and your customer</b>, not between
                    them and us. If you keep a deposit and your customer disputes it, it is yours
                    to resolve, and it is yours to be able to justify. Consumer law applies to you
                    the same as it would if you took the deposit in cash at the counter.
                </li>
                <li>
                    <b>Refunds are issued by you</b>, from your own account. We do not refund your
                    customers on your behalf and we cannot reverse a payment we never held.
                </li>
                <li>
                    <b>A chargeback is against you.</b> Stripe recovers it from your account, and
                    Stripe's own fees and rules apply. Say clearly, on your booking page, what
                    your deposit policy is. It is the single best defence against one.
                </li>
            </ul>
        </section>

        <section>
            <h2>Text messages</h2>
            <ul>
                <li>
                    <b>{{ $figures->smsIncluded() }} texts a month are included</b>, and the
                    allowance resets each billing period.
                </li>
                <li>
                    <b>Top-ups are {{ $figures->smsTopupBare() }} for
                    {{ $figures->smsTopupSize() }}</b>, charged when you buy them. Top-ups roll
                    over rather than expiring, because you paid for messages and not for a monthly
                    perk.
                </li>
                <li>
                    <b>The unit is a message segment, not a message.</b> Networks bill per
                    segment, and a text longer than 160 plain characters, or containing one
                    character outside the standard alphabet, is two or more. A long message
                    therefore costs more than one.
                </li>
                <li>
                    <b>There is a ceiling of {{ $figures->smsCeiling() }} segments per account per
                    billing period</b>, whatever you have topped up. It exists so a fault can
                    never run up a bill you did not expect. Ask and we will raise it.
                </li>
                <li>
                    <b>Texts go to people who asked to hear from you</b> about their own
                    appointment or a waitlist place they joined. The product is not a marketing
                    tool and must not be used as one. Sending unsolicited marketing through it
                    breaches these terms and UK marketing rules, and it is the fastest way to have
                    an account suspended.
                </li>
            </ul>
        </section>

        <section>
            <h2>Acceptable use</h2>
            <p>Do not use {{ config('product.name') }} to:</p>
            <ul>
                <li>Send marketing to people who did not ask for it.</li>
                <li>Store data you have no right to hold, or that you took from somewhere else.</li>
                <li>Break into it, load-test it without asking, or scrape it.</li>
                <li>Resell it as your own product without an agreement with us.</li>
                <li>Do anything with it that is against the law.</li>
            </ul>
            <p>
                We can suspend an account that is doing one of these. Where it is safe to do so we
                will tell you first and give you a chance to fix it.
            </p>
        </section>

        <section>
            <h2>What we promise, and what we do not</h2>
            <p>
                We will run the service with reasonable care, keep it patched, back it up, and
                tell you when something has gone wrong rather than letting you find out from a
                customer.
            </p>
            <p>
                <b>We do not promise it will never be down.</b> This is a new product from one
                person. There is no uptime guarantee attached to these terms, and if you need a
                contractual one, this is not the right product for you yet. We would rather say
                that here than sell you a number we cannot stand behind.
            </p>
        </section>

        <section>
            <h2>Liability</h2>
            <p>
                If we get something wrong and it costs you money, our liability to you is limited
                to what you have paid us in the twelve months before it happened. We are not
                liable for lost profit, lost bookings or lost goodwill.
            </p>
            <p>
                Nothing here limits liability that cannot be limited by law, including for death
                or personal injury caused by negligence, or for fraud.
            </p>
        </section>

        <section>
            <h2>Your data and your customers'</h2>
            <p>
                It stays yours. We hold it to run the product for you and for nothing else. What
                we collect and how long we keep it is in the
                <a href="{{ route('marketing.privacy') }}">privacy policy</a>, which forms part of
                these terms. You can export it at any time, in a format you can take elsewhere.
            </p>
        </section>

        <section>
            <h2>Changes to these terms</h2>
            <p>
                If we change them in a way that affects you, account holders are told by email
                before it applies, and the date at the top changes. If you do not accept a change,
                cancel. We will not backdate one.
            </p>
        </section>

        <section>
            <h2>Law</h2>
            <p>
                These terms are governed by the law of Scotland, and the Scottish courts have
                jurisdiction. If you are a consumer rather than a business, that does not take
                away rights you have where you live.
            </p>
            <p>
                Questions about any of this go to
                <a href="mailto:{{ $figures->contactEmail() }}">{{ $figures->contactEmail() }}</a>.
            </p>
        </section>
    </div>

@endsection
