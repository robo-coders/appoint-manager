{{--
    The two texts the waitlist actually sends.

    **These are quotations, not copywriting.** Both bodies are the real strings
    from `App\Services\Notifications\Notifier` — `waitlistOffer()` and
    `waitlistGone()` — with the salon name substituted the way the method
    substitutes it. If somebody rewrites those messages, this page is wrong, and
    `MarketingNavTest` asserts the two strings against the `Notifier` so that
    shows up as a failing test rather than as a stale page.

    **It is deliberately not a two-way exchange.** A mocked-up conversation with
    the client texting "yes" back would read better and would be a lie: nothing
    in this product parses an inbound SMS. The offer is claimed by opening the
    link, which is `book_url(null, 'offer/'.$offer->token)`, and the first person
    through it gets the slot. Showing a reply would be inventing a feature on a
    page whose whole job is to be checkable.

    Willow Street Grooming is the demo tenant's own salon name
    (`DemoDataSeeder`), used here as the example and labelled as one.
--}}
@php($salon = 'Willow Street Grooming')

<div class="thread">
    <div class="msg">
        {{-- "SMS" is a word, so it is not mono. Mono is for figures. --}}
        <p class="msg-meta">
            <span>To the {{ $figures->offerBatch() }} people waiting</span>
            <span>SMS</span>
        </p>
        <p class="text-14">{{ $salon }}: a slot is free. Claim: <span class="text-ink-2">…/offer/9f2c</span></p>
    </div>

    <div class="msg msg-later">
        <p class="msg-meta">
            <span>To everyone who was not first</span>
            <span>SMS</span>
        </p>
        <p class="text-14">{{ $salon }}: that slot was taken. We will text if another opens.</p>
    </div>
</div>

<p class="footnote">
    Word for word what goes out — both lines are the ones in the code that sends them. Nobody
    replies to a text: the link opens the slot, and the first person to take it has it. The rest get
    the second message, which is the one most systems do not bother to send.
</p>
