@extends('marketing.layout')

{{--
    Restyled onto the new frame, copy unchanged.

    Note that this page still says "monthly or yearly subscription" while
    `pricing.blade.php` now sells one monthly price. That is not a drift: the
    yearly plan still exists in `config('billing')` and in Stripe, so the terms
    are accurate — pricing simply no longer offers it. Recorded in DECISIONS.md.
--}}

@section('content')
    <section class="hero">
        <div class="wrap">
            <h1 class="text-34">Terms</h1>
            <div class="prose mt-6 text-17 text-ink-2">
                <p>
                    Use of the product is a monthly or yearly subscription after a 30-day trial.
                    Unpaid accounts become read-only in the admin app. Public booking for that
                    salon’s clients keeps working. You may cancel or pause from billing. Deposits
                    taken for the salon belong to the salon, not to us.
                </p>
            </div>
        </div>
    </section>
@endsection
