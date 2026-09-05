{{--
    The page's structured data, as one JSON-LD block.

    Built by `App\Support\MarketingSchema` and printed here. One `@graph` rather
    than several sibling script tags, so the organisation is declared once and
    the page-level nodes reference it by `@id`.

    `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` because the values are
    URLs and prose containing en dashes and curly apostrophes, and escaping both
    makes the block unreadable to a human without changing what a parser sees.

    `JSON_HEX_TAG` is the one that matters for safety: it escapes `<` and `>` to
    `<` / `>`, so a value containing `</script>` cannot close this
    element. The FAQ answers legitimately contain HTML — an `<a>` to the terms —
    which is exactly the case that would otherwise break out of the block.
--}}
@isset($schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endisset
