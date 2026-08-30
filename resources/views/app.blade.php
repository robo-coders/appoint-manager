<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-app-name="{{ config('app.name') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

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
    @php($surface = App\Support\Surface::current(request()->getHost(), request()->path()))
    <body
        class="font-sans antialiased"
        data-surface="{{ $surface->value }}"
        @if ($surface === App\Support\Surface::Admin) data-density="console" @endif
    >
        @inertia
    </body>
</html>
