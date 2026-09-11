@php
    $surface = App\Support\Surface::current(request()->getHost(), request()->path());
    $appearance = auth()->user()?->theme_preference?->value ?? 'system';
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-app-name="{{ config('product.name') }}"
    @if ($surface === App\Support\Surface::App && auth()->check())
        data-theme-preference="{{ $appearance }}"
        data-signed-in="1"
    @endif
>
    <head>
        <meta charset="utf-8">
        @if ($surface === App\Support\Surface::App && auth()->check())
            <script>
                (function () {
                    var root = document.documentElement;
                    var key = 'diarydesk.appearance';
                    var allowed = { light: 1, dark: 1, system: 1 };
                    var stored = null;
                    try { stored = localStorage.getItem(key); } catch (e) {}
                    var fromDom = root.getAttribute('data-theme-preference');
                    var preference = allowed[stored] ? stored : (allowed[fromDom] ? fromDom : 'system');
                    if (!stored) {
                        try { localStorage.setItem(key, preference); } catch (e) {}
                    }
                    var resolve = function () {
                        var current = null;
                        try { current = localStorage.getItem(key); } catch (e) {}
                        var pref = allowed[current] ? current : preference;
                        if (pref === 'dark' || pref === 'light') return pref;
                        try {
                            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                        } catch (e) {
                            return 'light';
                        }
                    };
                    var apply = function () {
                        root.setAttribute('data-theme', resolve());
                    };
                    apply();
                    try {
                        var media = window.matchMedia('(prefers-color-scheme: dark)');
                        var onChange = function () {
                            var current = null;
                            try { current = localStorage.getItem(key); } catch (e) {}
                            if ((allowed[current] ? current : preference) === 'system') apply();
                        };
                        if (media.addEventListener) media.addEventListener('change', onChange);
                        else if (media.addListener) media.addListener(onChange);
                    } catch (e) {}
                })();
            </script>
        @endif
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ config('product.name') }}</title>

        @include('partials.head')

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    {{--
        The console is denser than the operator app.

        `tokens.css` has carried `[data-density='console']` since the density
        pass — 32px controls, 28px rows, 13px fields — and nothing had ever set
        it, so the one surface it was written for rendered at operator density.
        Set once, on the surface's own root, which is the rule the token block
        states: no component takes a size prop for this.

        `Surface::current` rather than a route name, because every screen on the
        console is the console including its login page, and a route list is a
        thing that goes out of date. Not `Surface::fromHost`: that answers by
        host, and with subdomain routing off every surface shares one host — so
        it returns `App` for the console and this would have been dead code
        locally, in CI, and anywhere `SUBDOMAIN_ROUTING` is not set.

        `data-surface` is the same idea one level up, and it is set on all four
        surfaces so that the `[data-surface=…]` gate in `tokens.css` is a system
        rather than something the marketing site does to itself. Neither of the
        two surfaces this shell serves declares a page frame — both are
        full-bleed beside `--rail` — so this attribute currently changes nothing
        about how the app renders, which is deliberate: it is the hook, not a
        restyle.
    --}}
    <body
        class="font-sans antialiased"
        data-surface="{{ $surface->value }}"
        @if ($surface === App\Support\Surface::Admin) data-density="console" @endif
    >
        @inertia
    </body>
</html>
