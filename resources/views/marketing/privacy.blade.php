@extends('marketing.layout')

{{--
    Privacy.

    **Written to be accurate about this product rather than generic.** Every
    processor named here is one the application actually calls: Stripe for
    payments, Twilio for SMS, and the analytics and error-reporting services
    only when their keys are configured, which is why those two paragraphs are
    conditional rather than always-on claims.

    The controller / processor split is the part most policies of this kind get
    wrong, so it is stated first and stated plainly: for the salon's own account
    we are the controller; for the salon's customers we are the processor and
    the salon is the controller. That distinction decides who a person's rights
    request actually goes to.

    **It has not been through a solicitor and the page says so.** That notice is
    at the top, not the bottom, and it comes off when it has been reviewed.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">Privacy</div>
            <h1>What we hold, and why.</h1>
            <p class="sub">
                Written in plain English, and specific to what this product actually does with
                data rather than to what a policy template says.
            </p>
        </div>
    </section>

    <div class="doc">
        <div class="doc-caveat">
            <p>
                <b>This policy should be reviewed by a solicitor before the product handles real
                customer data at scale.</b> It has not been. It is an honest description of what
                {{ config('product.name') }} does today, written by the person who built it, and
                it is not legal sign-off.
            </p>
        </div>

        {{-- Bumped by hand when the policy changes. A date that moves on its own is not a version. --}}
        <p class="doc-updated">Last updated 3 September 2026</p>

        <section>
            <h2>Who is responsible for what</h2>
            <p>
                There are two different relationships here and they have different answers, so it
                is worth separating them before anything else.
            </p>
            <p>
                <b>Your salon's own account.</b> Your name, your email, your business details and
                your billing records. For that, {{ config('product.name') }} is the data
                controller under UK GDPR. We decide what to collect and why, and a request about
                it comes to us.
            </p>
            <p>
                <b>Your customers' details.</b> The people who book with you. For that,
                <b>you are the controller and we are the processor.</b> The data is yours. We hold
                it and act on it so the product works, and we do not use it for anything you have
                not asked for. If one of your customers asks you to delete their record, you can
                do it yourself from the customer screen, and if you would rather we did it, ask.
            </p>
        </section>

        <section>
            <h2>What we collect</h2>

            <h3>From you, when you sign up</h3>
            <ul>
                <li>Your name, email address and the name of your business.</li>
                <li>Your working hours, services, prices and staff, because that is the diary.</li>
                <li>Billing records: what you were charged, when, and whether it went through.</li>
                <li>
                    Ordinary server logs, including the IP address a request came from, kept
                    because a service with no logs cannot be debugged or defended.
                </li>
            </ul>

            <h3>About your customers, from you or from them</h3>
            <ul>
                <li>Name, mobile number and email address.</li>
                <li>Their appointments: what, when, with whom, and whether they turned up.</li>
                <li>
                    Whatever your trade's booking form asks for about the thing being booked. The
                    fields come from the trade you signed up under, not from us.
                </li>
                <li>Notes you write on a customer or an appointment.</li>
                <li>Whether they are on a waitlist, and which days and times suit them.</li>
            </ul>

            <h3>Payments</h3>
            <p>
                Deposits are taken through <b>Stripe</b>, into your own Stripe account.
                <b>We never see or store a card number.</b> The card details go from your
                customer's browser to Stripe directly. What we keep is Stripe's reference for the
                payment, the amount, and whether it succeeded.
            </p>

            <h3>Text messages</h3>
            <p>
                Waitlist offers and reminders are sent through <b>Twilio</b>. To send a text we
                have to give Twilio the mobile number and the message. We keep a record of what
                was sent, to which booking, and whether the network accepted it, because a salon
                asking "did they get the text" needs an answer.
            </p>
        </section>

        <section>
            <h2>Why we are allowed to hold it</h2>
            <p>
                Under UK GDPR every use of personal data needs a lawful basis. Ours are:
            </p>
            <ul>
                <li>
                    <b>Contract.</b> We cannot run your diary, take a deposit or send a
                    confirmation without the details of the booking.
                </li>
                <li>
                    <b>Legitimate interests.</b> Keeping the service secure, keeping logs, and
                    preventing abuse of the messaging.
                </li>
                <li>
                    <b>Legal obligation.</b> Billing and tax records, which we are required to
                    keep whether we want to or not.
                </li>
            </ul>
            <p>
                We do not sell customer lists, we do not share them between salons, and we do not
                use one salon's customer data to market to anybody.
            </p>
        </section>

        <section>
            <h2>How long it is kept</h2>
            <ul>
                <li>
                    <b>While your account exists.</b> Cancelling a subscription stops the billing.
                    It does not delete the account, so that you can come back to it or export from
                    it.
                </li>
                <li>
                    <b>A customer record, until you delete it.</b> You can delete one permanently
                    from the customer screen at any time, and export one at any time.
                </li>
                <li>
                    <b>Billing and tax records for six years.</b> That is what HMRC requires of us
                    and it applies whether or not you are still a customer.
                </li>
                <li>
                    <b>Message and server logs, months rather than years.</b> They exist to
                    diagnose faults, and old ones cannot do that.
                </li>
            </ul>
            <p>
                Ask us to delete your account and everything in it, and we will, within one month.
            </p>
        </section>

        <section>
            <h2>Who else touches it</h2>
            <p>
                These are our processors. Each one is here because the product calls it, not
                because it is a list a policy is expected to have.
            </p>
            <ul>
                <li><b>Stripe</b> — card payments, deposits and our own subscription billing.</li>
                <li><b>Twilio</b> — sending text messages.</li>
                <li><b>Our hosting and email providers</b> — running the servers and delivering email.</li>
                @if (config('services.plausible.domain'))
                    <li>
                        <b>Plausible</b> — visitor numbers on this marketing site. No cookies, no
                        advertising identifiers, and nothing that identifies a person.
                    </li>
                @endif
                @if (config('services.sentry.dsn'))
                    <li>
                        <b>Sentry</b> — error reports, so a fault is fixed rather than guessed at.
                    </li>
                @endif
            </ul>
            <p>
                Data is stored in the UK or the EU. Where a processor moves data outside that, it
                is under the safeguards UK GDPR requires of them.
            </p>
        </section>

        <section>
            <h2>Cookies</h2>
            <p>
                The marketing site sets no cookies at all. Signing in to the product sets a
                session cookie, which is what keeps you signed in, and one that protects forms
                from being submitted from another site. There are no advertising cookies and
                nothing that follows you elsewhere.
            </p>
        </section>

        <section>
            <h2>Your rights</h2>
            <p>Under UK GDPR you can ask for:</p>
            <ul>
                <li><b>Access</b> — a copy of what we hold about you.</li>
                <li><b>Correction</b> — anything wrong put right.</li>
                <li><b>Deletion</b> — it removed, where we are not required to keep it.</li>
                <li><b>Restriction or objection</b> — us to stop a particular use of it.</li>
                <li><b>Portability</b> — it in a form you can take elsewhere.</li>
            </ul>
            <p>
                If you are a customer of a salon rather than the salon itself, ask the salon
                first. They hold the record and they can act on it immediately. If they cannot
                help, write to us and we will.
            </p>
            <p>
                Requests go to
                <a href="mailto:{{ $figures->contactEmail() }}">{{ $figures->contactEmail() }}</a>.
                Include enough for us to find the record and be sure it is yours. We answer within
                one month, free of charge.
            </p>
            <p>
                If you are not satisfied with how we handle it, you can complain to the
                Information Commissioner's Office, which is the UK's data protection regulator.
                We would rather you told us first, but you do not have to.
            </p>
        </section>

        <section>
            <h2>Changes</h2>
            <p>
                If this changes in a way that matters, the date at the top changes and account
                holders are told by email. We will not quietly rewrite it.
            </p>
        </section>
    </div>

@endsection
