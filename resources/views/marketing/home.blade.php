@extends('marketing.layout')

{{--
    The home page.

    **What changed from direction A, and why.** The approved direction led with a
    ledger comparing a competitor's per-booking fee against our £39. That
    argument only wins above roughly 32 appointments a month, so the groomer this
    product is actually for — twenty appointments a month — reads it, does the
    arithmetic it invites her to do, and correctly concludes we are the more
    expensive option. The page hands her a calculator and loses.

    So the ledger moved to `pricing.blade.php`, reframed as a positioning
    argument, and this page leads with recovered revenue instead: one refilled
    cancellation covers the month. That claim is true at twenty appointments and
    at eighty, it rests on our own £39 rather than on somebody else's fee, and no
    competitor can invalidate it by changing a setting. It is also the thing the
    free tools do not do.

    The editorial spine — claim left, evidence right, from 1000 up — is direction
    A's and is kept throughout. Every section fills both halves; an empty right
    column is an unfilled grid, not whitespace.
--}}

@section('content')

    {{--
        Hero. The headline sits in `--arg` and the sum is the dominant element,
        which is what lets 34px hold at 1280 without a display size the scale
        does not have.
    --}}
    <section class="hero">
        <div class="wrap split">
            <div class="claim">
                <h1 class="text-34">One refilled slot covers the month.</h1>
                <p class="sub text-17 text-ink-2">
                    A client cancels on Thursday. The people on your waitlist get a text within
                    the minute, the first one takes the hour, and an appointment that was about to
                    evaporate is back on the books.
                </p>
                @include('marketing.partials.cta', [
                    'label' => 'Start free trial',
                    'note' => $figures->trialDays().' days. No card.',
                ])
            </div>

            <div class="evidence">
                @include('marketing.partials.refill-sum')
            </div>
        </div>
    </section>

    {{--
        The mechanism, and it is the real one: six steps, each traceable to a
        class or a config value in this repository. No abstraction, no diagram of
        a diagram.
    --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">The hour does not just sit there empty</h2>
                <p class="lede">
                    This is the part a free diary does not do. It records the cancellation and
                    leaves the hole in your day for you to notice.
                </p>
            </div>
            <div class="evidence">
                <ol class="steps">
                    <li>
                        <p class="text-14">
                            A client cancels. The diary shows the hour as a freed slot instead of
                            quietly hiding the cancelled row, which is what it used to do.
                        </p>
                    </li>
                    <li>
                        <p class="text-14">
                            It works out who actually wants it — the same service, on a day and at
                            a time of day they told you they could do. Not everyone on the list.
                        </p>
                    </li>
                    <li>
                        <p class="text-14">
                            The first {{ $figures->offerBatch() }} of them get a text at once,
                            automatically, the moment the cancellation lands. Not one at a time,
                            waiting for each to answer.
                        </p>
                    </li>
                    <li>
                        <p class="text-14">
                            They have {{ $figures->offerMinutes() }} minutes. The first one to open
                            the link has the slot; the link stops working for everybody else.
                        </p>
                    </li>
                    <li>
                        <p class="text-14">
                            Everyone who missed it is told so. If nobody claims it at all, the next
                            {{ $figures->offerBatch() }} get their turn.
                        </p>
                    </li>
                    <li>
                        <p class="text-14">
                            What it added up to is the first figure on your dashboard, under
                            <span class="font-medium">Recovered from waitlist</span> — this month's
                            total, and how many appointments it came from.
                        </p>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- What actually goes out. Quoted from the code that sends it. --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">Both texts, word for word</h2>
                <p class="lede">
                    Including the second one, which is the message most systems do not bother to
                    send.
                </p>
            </div>
            <div class="evidence">
                @include('marketing.partials.messages')
            </div>
        </div>
    </section>

    {{--
        The deposit — the other half, and deliberately *not* multiplied out.

        The page this replaces ran "a £45 slot, three no-shows a week is £135
        gone, every week", which annualised to more than the product costs by an
        order of magnitude and read as either an enormous bargain or an
        exaggeration. It also called revenue "gone" as though it were profit.
        There is no multiplication here at all: one missed appointment, one
        deposit, both stated.
    --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">And the ones who never turn up</h2>
                <p class="lede">
                    A deposit does not punish anybody. It means the hour is worth something before
                    the dog arrives.
                </p>
            </div>
            <div class="evidence">
                <dl class="facts">
                    <div>
                        <dt>A missed appointment costs you the whole slot.</dt>
                        <dd>
                            On the price list we set you up with, a
                            {{ $figures->slotName() }} is
                            <span class="font-mono">{{ $figures->slot()->formatted() }}</span> and
                            {{ $figures->slotMinutes() }} minutes of your day. Nobody pays you for
                            it and nobody else could have had it.
                        </dd>
                    </div>
                    <div>
                        <dt>
                            The deposit holds
                            <span class="font-mono">{{ $figures->deposit()->formatted() }}</span> of
                            it.
                        </dt>
                        <dd>
                            Taken when she books, and it comes off the bill on the day. If she
                            cancels in time she gets it back automatically, from your own Stripe
                            account, without either of you emailing anybody.
                        </dd>
                    </div>
                    <div>
                        <dt>A cancelled appointment is worth more than a held one.</dt>
                        <dd>
                            Because it goes back out to the waitlist. The deposit is what stops the
                            hour being wasted; the waitlist is what sells it twice.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- Ownership: the half of the argument that survives everything else. --}}
    <section class="sec sec-airy">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">Where the money lands</h2>
                <p class="lede">
                    Deposits are not much use to you if they sit in somebody else's balance until
                    somebody else releases them.
                </p>
            </div>
            <div class="evidence">
                <dl class="facts">
                    <div>
                        <dt>The Stripe account is in your name.</dt>
                        <dd>
                            You open it during setup — your salon, your bank details. We are not
                            the merchant on your clients' statements and we never hold their money.
                        </dd>
                    </div>
                    <div>
                        <dt>Deposits arrive in your bank, not ours.</dt>
                        <dd>
                            Refunds are yours to give, from your own account, without asking us
                            first.
                        </dd>
                    </div>
                    <div>
                        <dt>Your clients are yours.</dt>
                        <dd>
                            Names, dogs, breeds and appointment history all export to a
                            spreadsheet whenever you want. There is no marketplace and no
                            pet-owner app, so nobody is shown three other groomers on the way to
                            booking with you.
                        </dd>
                    </div>
                    <div>
                        <dt>One booking page, and it is yours.</dt>
                        <dd>
                            Your name, your services, your hours, your colour. Not a profile inside
                            somebody else's directory.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- The price, once, with the way to the argument for it. --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <div class="price">
                    <span class="amt">{{ $figures->monthlyBare() }}</span>
                    <span class="text-17 text-ink-2">a month</span>
                </div>
                <p class="lede">
                    One price. {{ $figures->trialDays() }} days free, and we do not ask for a card
                    to start.
                </p>
                <div class="mt-8">
                    @include('marketing.partials.cta', ['label' => 'Start free trial'])
                </div>
            </div>
            <div class="evidence">
                <p class="max-w-measure text-15">
                    No tiers, no per-booking charge to you, and no charge at all to the people who
                    book with you. There is genuinely good free grooming software and we are not
                    going to pretend otherwise — but somebody is paying for it, and on the free
                    plans it is your clients.
                </p>
                <p class="mt-4">
                    <a class="m-link text-14" href="{{ route('marketing.pricing') }}">Why we charge you instead</a>
                </p>
            </div>
        </div>
    </section>

    {{--
        The questions, including the two that go badly for us. Conceding is what
        makes the rest of the page readable as honest rather than as a pitch.
    --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">The questions we actually get</h2>
                <p class="lede">Including the two we lose.</p>
            </div>
            <div class="evidence">
                <dl class="qa">
                    <div>
                        <dt>I only do twenty appointments a month. Is this worth it?</dt>
                        <dd>
                            The sum at the top of this page does not have your appointment count in
                            it, which is the honest answer: one refilled cancellation covers the
                            month whether you do twenty or eighty. What matters is not how busy you
                            are, it is whether you get cancellations and whether anybody is waiting.
                        </dd>
                    </div>
                    <div>
                        <dt>And if nobody is on the waitlist?</dt>
                        <dd>
                            Then nothing goes out and nothing is recovered, and this is a diary with
                            deposits and reminders for
                            <span class="font-mono">{{ $figures->monthly()->formatted() }}</span> a
                            month. The free tools do those too. The waitlist is the reason to be
                            here, so if your book never has a gap in it and nobody ever asks you to
                            call them, we are not obviously worth the money and we would rather say
                            so now.
                        </dd>
                    </div>
                    <div>
                        <dt>Will asking for a deposit lose me clients?</dt>
                        <dd>
                            The ones who vanish on a Saturday might. A
                            <span class="font-mono">{{ $figures->deposit()->formatted() }}</span>
                            hold is not a punishment: it comes off the bill on the day, and it is
                            how the book stays honest.
                        </dd>
                    </div>
                    <div>
                        <dt>What happens to the book I already have?</dt>
                        <dd>
                            We import names, dogs and the next fortnight from a spreadsheet before
                            your booking page goes live, so you are not starting from an empty
                            diary. For the first ten salons we will come and do it with you.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    @include('marketing.partials.no-proof', ['heading' => 'From groomers using it'])

@endsection
