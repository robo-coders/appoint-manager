@php
    $demoActs = [
        ['label' => 'Booking portal', 'dur' => 4000],
        ['label' => 'Waitlist fills', 'dur' => 7000],
        ['label' => 'Diary', 'dur' => 4000],
        ['label' => 'No-show', 'dur' => 4500],
        ['label' => 'Staff', 'dur' => 3500],
        ['label' => 'Loyalty', 'dur' => 4000],
    ];
    $demoVerticals = ['Dog Groomers', 'Barbers', 'Dentists', 'Tattoo Studios'];
@endphp

<section class="demo" data-dm-section>
    <div class="demo-inner">
        <div class="demo-intro">
            <div class="dm-eyebrow">Self-running · no clicks needed</div>
            <h2>A Thursday, filling itself in.</h2>
            <p>One cancellation, one text, four minutes. This is the whole loop — a real day moving through {{ config('product.name') }} while nobody picks up the phone.</p>
        </div>

        <div class="demo-switch">
            <div class="dm-eyebrow">Same diary, same automation</div>
            <div class="demo-switch-list" role="group" aria-label="Choose a trade to see the same diary with its own services and prices" data-dm-switcher>
                @foreach ($demoVerticals as $index => $label)
                    <button
                        type="button"
                        class="demo-switch-btn{{ $index === 0 ? ' is-on' : '' }}"
                        aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"
                        data-dm-vertical="{{ $label }}"
                    >
                        {{ $label }}
                        <span class="demo-switch-mark" aria-hidden="true"></span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="demo-frame">
            <div class="demo-chrome">
                <div class="demo-chrome-mark" aria-hidden="true">D</div>
                <div class="dm-eyebrow demo-chrome-context" data-dm-context>Customer booking portal</div>
                <span class="demo-chrome-gap"></span>
                <div class="dm-eyebrow demo-chrome-biz" data-dm-biz>Bramble &amp; Bone</div>
                <div class="dm-mono demo-chrome-clock" data-dm-clock>13:58</div>
            </div>

            <div class="demo-stage" data-dm-stage></div>
            <div class="demo-cursor" aria-hidden="true" data-dm-cursor><span></span></div>
        </div>

        <div class="demo-scrub" data-dm-scrub>
            @foreach ($demoActs as $index => $act)
                <div class="dm-scrub-cell{{ $index === 0 ? ' is-on' : '' }}" style="flex:{{ $act['dur'] }}">
                    <div class="dm-scrub-track"><div class="dm-scrub-bar"></div></div>
                    <div class="dm-scrub-label">{{ $act['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

@vite(['resources/js/marketing-demo.ts'])
