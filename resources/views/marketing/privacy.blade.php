@extends('marketing.layout')

{{--
    Restyled onto the new frame, copy unchanged.
--}}

@section('content')
    <section class="hero">
        <div class="wrap">
            <h1 class="text-34">Privacy</h1>
            <div class="prose mt-6 text-17 text-ink-2">
                <p>
                    We store salon and client data in the UK/EU for providing the booking product. A
                    salon can export or hard-delete a client record from the admin app. We do not
                    sell client lists. Platform billing uses Stripe on our account; salon deposits
                    use Stripe Connect on the salon’s account. Marketing analytics, if enabled, is a
                    privacy-respecting script with no advertising cookies.
                </p>
            </div>
        </div>
    </section>
@endsection
