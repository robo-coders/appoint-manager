@extends('marketing.layout')

{{--
    Contact, and the in-person demo offer.

    **The form posts for real and does not email anybody yet.** `POST /contact`
    validates, rate-limits, drops anything that trips the honeypot, and writes
    the enquiry to the application log at `info`. It does not send mail. That is
    a deliberate one-step-short: the copy under the button says so, and
    `MarketingController::sendContact()` says where the one line goes that turns
    it into a Mailable. Nothing here pretends an enquiry has reached a person
    when it has reached a log file.

    The page is deliberately **not** in the `cache.headers:public` group the
    rest of the surface is in. It carries a CSRF token, and a shared cache
    handing one visitor's token to another is a 419 on submit for everybody but
    the first person through.
--}}

@section('content')

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">Contact</div>
            <h1>Ask me anything about it.</h1>
            <p class="sub">
                Setup, deposits, moving off paper, whether it fits how you actually work. It comes
                to one person and you will get a real answer.
            </p>
        </div>
    </section>

    @if (session('contact.sent'))
        <div class="flash">
            <p role="status">{{ session('contact.sent') }}</p>
        </div>
    @endif

    <div class="contact-grid">
        <form class="form" method="POST" action="{{ route('marketing.contact.send') }}" novalidate>
            @csrf

            {{--
                The honeypot. A real person never sees it, so anything in it came
                from something filling every input on the page. Named plausibly
                for the same reason: `honeypot` in the DOM is a hint.
            --}}
            <div class="trap" aria-hidden="true">
                <label for="company_website">Company website</label>
                <input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
            </div>

            <div class="field">
                <label for="name">Your name</label>
                <input
                    type="text" id="name" name="name" value="{{ old('name') }}"
                    autocomplete="name" required
                    @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                >
                @error('name')<span class="field-error" id="name-error">{{ $message }}</span>@enderror
            </div>

            <div class="field">
                <label for="business">Business name</label>
                <input
                    type="text" id="business" name="business" value="{{ old('business') }}"
                    autocomplete="organization" required
                    @error('business') aria-invalid="true" aria-describedby="business-error" @enderror
                >
                @error('business')<span class="field-error" id="business-error">{{ $message }}</span>@enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email" id="email" name="email" value="{{ old('email') }}"
                    autocomplete="email" inputmode="email" required
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                >
                @error('email')<span class="field-error" id="email-error">{{ $message }}</span>@enderror
            </div>

            <div class="field">
                <label for="phone">Phone <span class="hint">optional</span></label>
                <input
                    type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                    autocomplete="tel" inputmode="tel"
                    @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                >
                @error('phone')<span class="field-error" id="phone-error">{{ $message }}</span>@enderror
            </div>

            <div class="field">
                <label for="message">What would you like to know?</label>
                <textarea
                    id="message" name="message" rows="6" required
                    @error('message') aria-invalid="true" aria-describedby="message-error" @enderror
                >{{ old('message') }}</textarea>
                @error('message')<span class="field-error" id="message-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-submit">
                <button type="submit">Send it</button>
                <span class="note">Usually answered the same day.</span>
            </div>
        </form>

        <div class="aside">
            <h2>Or come and see it.</h2>
            <p>
                If your business is in or around East Kilbride, I will come to you. Bring your
                appointment book. We will put your services and your next fortnight into it while
                you watch, and you can decide afterwards.
            </p>
            <p>
                It takes about an hour and it costs nothing. There is no obligation at the end of
                it, and I am not going to leave a card machine on your counter.
            </p>

            <div class="block">
                <h2>Email instead</h2>
                <p>
                    <a href="mailto:{{ $figures->contactEmail() }}">{{ $figures->contactEmail() }}</a>
                </p>
                <p>
                    For a question about your own data rather than about buying anything, the
                    <a href="{{ route('marketing.privacy') }}">privacy policy</a> says what to
                    include.
                </p>
            </div>

            <div class="block">
                <h2>Just want to try it?</h2>
                <p>
                    You do not have to talk to anybody. The trial is
                    {{ $figures->trialDays() }} days, takes no card, and you can set it up
                    yourself in an evening.
                </p>
                <p><a href="{{ app_url('register') }}">Start the free trial</a></p>
            </div>
        </div>
    </div>

@endsection
