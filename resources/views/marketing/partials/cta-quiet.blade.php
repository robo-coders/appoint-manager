{{-- A secondary call to action for mid-page, where a filled button would shout. --}}
<a
    href="{{ $href ?? app_url('register') }}"
    class="inline-flex min-h-tap items-center text-14 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
>{{ $label }}</a>
