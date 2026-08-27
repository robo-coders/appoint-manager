{{--
    The plain text part.

    `{!! !!}` throughout, and that is correct here rather than risky: there is no
    HTML in this document, so there is no markup for a value to break out of —
    and Blade's default escaping is actively wrong, because a salon called
    "Paw & Order" arrives in somebody's text-only mail client as "Paw &amp;
    Order". Which it did.

    Every value comes from the Mailable, the same ones the HTML part uses, so
    the two cannot drift.
--}}
{!! $heading !!}

{!! $lede !!}

{!! $rowsText !!}

Open the diary: {!! $diaryUrl !!}

{!! $footer !!}
