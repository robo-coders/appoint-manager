{{--
    The marketing call to action.

    Classes are copied from Components/ui/Button.vue (primary variant) so the
    Blade marketing site and the Vue admin app render the same button rather
    than two that drift apart.
--}}
<a
    href="{{ $href ?? app_url('register') }}"
    class="inline-flex min-h-tap items-center justify-center rounded bg-ink px-4 text-14 font-medium text-white transition duration-fast ease-product hover:opacity-90 active:translate-y-px"
>{{ $label }}</a>
