{{--
    The shell every transactional email uses.

    **Email is a different medium and this does not chase pixel parity with the
    app.** Outlook on Windows renders with Word's engine: no flexbox, no grid,
    no `max-width` on a `div`, and stylesheets stripped. So this is nested
    tables with inline styles, which is the only layout that survives — and the
    divergences that follow from it are named where they happen.

    What is kept from the design system: the palette (read from `tokens.css` by
    `App\Support\DesignTokens`, so there is one source and nothing to drift), the
    hairline instead of a border-and-shadow, one ink action, sentence case, and
    mono tabular numerals for every time and every amount.

    Everything the shell needs is passed as an attribute. An anonymous component
    has its own scope — only `$slot` and the attributes named on the tag cross
    into it — so a value merely present in the Mailable's `with()` is *not*
    visible here, it silently falls through to the default. That is how the
    operator's agenda came out signed "Sent by Appoint Manager on behalf of
    Appoint Manager": `$footer` was passed to the view and never to the shell.

    What is deliberately different, and why:

      - **A dark palette exists here.** `DESIGN.md` says the product is light
        only, and that stands for the app. It cannot stand for email: Apple Mail
        and Outlook repaint a message for dark mode whether or not we have an
        opinion, and an auto-inverted warm-paper email comes back as muddy
        blue-grey with the ink action inverted to near-white. Specifying beats
        being repainted. The dark values are the same roles, not a second design,
        and they live in `tokens.css` under `[data-scheme='mail-dark']` — one
        file still knows what this product looks like.
      - **No web font.** `@font-face` in email is unreliable and a fallback
        stack is what most clients will use anyway, so the stack is named
        honestly and the type is sized for it.
      - **A fixed 560px table.** The app has fluid columns; email has one
        column, because Word cannot do anything else.
--}}
@php
    $ink = App\Support\DesignTokens::value('ink');
    $ink2 = App\Support\DesignTokens::value('ink-2');
    $paper = App\Support\DesignTokens::value('paper');
    $paperSunk = App\Support\DesignTokens::value('paper-sunk');
    $white = App\Support\DesignTokens::value('white');
    $sans = 'Geist, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    $mono = '"Geist Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

    // The dark palette, from `tokens.css` like every other colour. It lives in
    // its own selector rather than in `:root` — see the note beside it there.
    $darkPaper = App\Support\DesignTokens::value('mail-dark-paper');
    $darkSheet = App\Support\DesignTokens::value('mail-dark-sheet');
    $darkRule = App\Support\DesignTokens::value('mail-dark-rule');
    $darkInk = App\Support\DesignTokens::value('mail-dark-ink');
    $darkInk2 = App\Support\DesignTokens::value('mail-dark-ink-2');
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{--
            Both, and both matter. `color-scheme` tells the client we have an
            opinion about dark mode, which is what stops iOS Mail and Outlook
            force-inverting the whole message; `supported-color-schemes` is the
            older spelling that Apple Mail still reads.
        --}}
        <meta name="color-scheme" content="light dark">
        <meta name="supported-color-schemes" content="light dark">
        <title>{{ $subject }}</title>
        <style>
            /*
                Stripped by Outlook and honoured by everything else, which is
                exactly the right split: nothing structural lives here. The
                layout is inline on the tables below and works with this
                stylesheet thrown away.
            */
            @media (prefers-color-scheme: dark) {
                .sheet { background-color: {{ $darkPaper }} !important; }
                .card { background-color: {{ $darkSheet }} !important; border-color: {{ $darkRule }} !important; }
                .ink { color: {{ $darkInk }} !important; }
                .ink-2 { color: {{ $darkInk2 }} !important; }
                .rule { border-color: {{ $darkRule }} !important; }
                .action { background-color: {{ $darkInk }} !important; }
                /*
                    The anchor, not only the cell it sits in. The label carries
                    an inline colour, which beats a class on its parent — so
                    without this the ink button inverts its fill and keeps its
                    white text, and the one action in the message disappears.
                */
                .action-label { color: {{ $darkPaper }} !important; }
            }

            @media (max-width: 600px) {
                .sheet-pad { padding: 24px 16px !important; }
                .stack { display: block !important; width: 100% !important; text-align: left !important; }
            }
        </style>
    </head>
    <body class="sheet" style="margin:0;padding:0;width:100%;background-color:{{ $paper }};">
        {{--
            The preheader: the line a client shows in the inbox list after the
            subject. Left to itself it is whatever the first words of the body
            happen to be — usually the salon's name twice. Hidden in the message
            and written on purpose.
        --}}
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
            {{ $preheader ?? '' }}
        </div>

        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               class="sheet" style="background-color:{{ $paper }};">
            <tr>
                <td align="center" class="sheet-pad" style="padding:40px 24px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560"
                           style="width:560px;max-width:560px;">
                        {{-- Who this is from, before anything else. --}}
                        <tr>
                            <td class="ink-2" style="padding:0 0 24px;font-family:{{ $sans }};font-size:13px;line-height:1.5;color:{{ $ink2 }};">
                                {{ $from ?? config('product.name') }}
                            </td>
                        </tr>

                        <tr>
                            <td class="card" style="background-color:{{ $white }};border:1px solid rgba(24,23,20,0.10);border-radius:6px;padding:32px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                    <tr>
                                        <td class="ink" style="font-family:{{ $sans }};font-size:22px;line-height:1.3;font-weight:500;color:{{ $ink }};letter-spacing:-0.01em;">
                                            {{ $heading }}
                                        </td>
                                    </tr>
                                    @isset($lede)
                                        <tr>
                                            <td class="ink-2" style="padding:12px 0 0;font-family:{{ $sans }};font-size:15px;line-height:1.55;color:{{ $ink2 }};">
                                                {{ $lede }}
                                            </td>
                                        </tr>
                                    @endisset
                                    <tr>
                                        <td style="padding:24px 0 0;">
                                            {{ $slot }}
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        {{--
                            The foot carries the product's name and nothing else.
                            An unsubscribe link would be wrong on all seven of
                            these: they are transactional — a confirmation, a
                            reminder, a cancellation — and there is nothing to
                            unsubscribe from that is not the appointment itself.
                        --}}
                        <tr>
                            <td class="ink-2" style="padding:24px 0 0;font-family:{{ $sans }};font-size:12px;line-height:1.5;color:{{ $ink2 }};">
                                {{ $footer ?? 'Sent by '.config('product.name').' on behalf of '.($from ?? config('product.name')).'.' }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
