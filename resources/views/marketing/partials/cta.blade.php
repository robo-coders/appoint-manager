{{--
    The primary call to action.

    **This file was the last entry on the `check:components` allow-list**, and it
    is why phase 11 was the phase that clears it. What it used to be was an
    `<a>` wearing Button.vue's primary-variant utility classes, copied out by
    hand — `inline-flex min-h-tap items-center justify-center rounded bg-ink px-4
    text-14 font-medium text-white …` — with a comment saying the classes were
    copied so the two would not drift, which is precisely the drift the
    `copied-control` rule exists to catch. Two copies of a button do not stay in
    step because a comment asks them to.

    It is `.m-btn` now: one declaration, in `marketing.css`, reading the same
    tokens Button.vue reads. `ui/Button` itself is unavailable here — it is a Vue
    component and this surface is Blade with no Vue in it by design (REBUILD.md,
    phase 11), the same structural reason the mail tree cannot use `ui/Table`.

    `$note` is the reassurance that belongs beside the button rather than under
    it, so "no card" is read before the click and not after.
--}}
<div class="act">
    <a class="m-btn" href="{{ $href ?? app_url('register') }}">{{ $label }}</a>
    @isset($note)
        <span class="text-13 text-ink-2">{{ $note }}</span>
    @endisset
</div>
