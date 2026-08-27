{{--
    The shell every error page uses.

    **It links nothing and queries nothing**, and that is the whole design of it.

    A 503 renders while the database is down and, during a deploy, while the
    Vite manifest is halfway through being replaced — so `@vite()` here is a
    page that 500s at exactly the moment it is the only page left. The tokens
    are inlined instead, read from `resources/css/tokens.css` by
    `App\Support\DesignTokens`, so there is one palette and nothing to drift.
    The rules below are written by hand because the app's stylesheet is a build
    artefact and this page cannot depend on one.

    No Inertia either: `@inertia` needs a page component, a manifest and a
    working JS bundle, none of which an error page can assume.

    The type is the product's, and it degrades honestly — Geist is not loaded
    here, because a `@font-face` pointing at a hashed build asset is one more
    thing to be missing. The system grotesque is what `--font-sans` already
    falls back to, and an error page in the fallback face is the design working
    as designed rather than a different design.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <meta name="theme-color" content="{{ App\Support\DesignTokens::value('paper') }}">
        <title>{{ $page['title'] }} — {{ config('app.name') }}</title>
        <style>
            :root { {!! App\Support\DesignTokens::root() !!} }

            *, *::before, *::after { box-sizing: border-box; }

            html { -webkit-text-size-adjust: 100%; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background: var(--paper);
                color: var(--ink);
                font-family: var(--font-sans);
                font-size: var(--text-14);
                line-height: var(--leading-body);
                -webkit-font-smoothing: antialiased;
            }

            /*
                A text column, left-aligned, centred in the viewport above 768.

                The first version pinned it to the left margin, the way the auth
                surface does. That was wrong here and rendering it at 1280 made
                it obvious: on auth the left column is balanced by a paper-sunk
                panel filling the rest of the width, and an error page has no
                panel — so the same treatment put a 380px column of type hard
                against the left edge of 780px of nothing, which reads as a page
                that failed to finish loading rather than as restraint.

                Centring a *column* is not the centred card the design brief
                rules out. There is no border, no fill and nothing raised; the type
                stays left-aligned and ragged-right, and what moves is only where
                the measure sits in the viewport.
            */
            main {
                width: 100%;
                max-width: var(--auth-form);
                padding: var(--space-12) var(--space-6);
                margin: 0;
            }

            @media (min-width: 768px) {
                body { justify-content: center; }

                main {
                    padding: var(--space-16) var(--space-12);
                    max-width: 34rem;
                    margin: 0 auto;
                }
            }

            .eyebrow {
                margin: 0;
                font-family: var(--font-mono);
                font-variant-numeric: tabular-nums;
                font-size: var(--text-12);
                color: var(--ink-2);
            }

            .eyebrow[data-tone='danger'] { color: var(--danger); }

            h1 {
                margin: var(--space-4) 0 0;
                font-size: var(--text-24);
                font-weight: 500;
                letter-spacing: var(--tracking-24);
                line-height: var(--leading-heading);
            }

            @media (min-width: 768px) {
                h1 { font-size: var(--text-34); letter-spacing: var(--tracking-34); line-height: var(--leading-display); }
            }

            .body {
                margin: var(--space-3) 0 0;
                max-width: 46ch;
                color: var(--ink-2);
            }

            /* The ways out. A list of real destinations, not a button. */
            .ways { margin: var(--space-8) 0 0; padding: 0; list-style: none; border-top: 1px solid var(--rule); }

            .ways li { border-bottom: 1px solid var(--rule); }

            .ways a {
                display: block;
                padding: var(--space-3) 0;
                min-height: var(--tap);
                color: var(--ink);
                text-decoration: none;
            }

            .ways a:hover { text-decoration: underline; text-underline-offset: 4px; }

            .ways a:focus-visible { outline: none; box-shadow: var(--focus-ring); border-radius: var(--radius); } /* design-tokens-ignore: this IS var(--focus-ring), the one shadow the system has; the rule that flags it reads Tailwind class names and only applies its `style` scope to .css files, and this is CSS inside a .blade.php */

            .ways .note { display: block; font-size: var(--text-13); color: var(--ink-2); }

            .foot {
                margin: var(--space-8) 0 0;
                font-size: var(--text-13);
                color: var(--ink-2);
            }

            .ref {
                font-family: var(--font-mono);
                font-variant-numeric: tabular-nums;
                font-size: var(--text-12);
                color: var(--ink);
            }
        </style>
    </head>
    <body>
        <main>
            <p class="eyebrow" data-tone="{{ $page['tone'] }}">{{ $page['eyebrow'] }}</p>
            <h1>{{ $page['title'] }}</h1>
            <p class="body">{{ $page['body'] }}</p>

            @if (! empty($page['ways']))
                <ul class="ways">
                    @foreach ($page['ways'] as $way)
                        <li>
                            <a href="{{ $way['href'] }}">
                                {{ $way['label'] }}
                                @isset($way['note'])<span class="note">{{ $way['note'] }}</span>@endisset
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @yield('extra')
        </main>
    </body>
</html>
