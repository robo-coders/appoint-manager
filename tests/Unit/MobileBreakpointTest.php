<?php

function tailwindScreens(): array
{
    $config = base_path('tailwind.config.js');

    expect($config)->toBeReadableFile();

    $source = (string) file_get_contents($config);

    if (preg_match('/screens:\s*\{(.*?)\}/s', $source, $block) !== 1) {
        return [
            'sm' => 640,
            'md' => 768,
            'lg' => 1024,
            'xl' => 1280,
            '2xl' => 1536,
        ];
    }

    preg_match_all('/[\'"]?([a-z0-9]+)[\'"]?\s*:\s*[\'"](\d+)px[\'"]/i', $block[1], $pairs, PREG_SET_ORDER);

    return array_reduce($pairs, function (array $carry, array $pair): array {
        $carry[$pair[1]] = (int) $pair[2];

        return $carry;
    }, []);
}

it('mirrors the Tailwind breakpoint the responsive classes are written against', function () {
    expect(config('ui.mobile_breakpoint'))->toBe(tailwindScreens()['md']);
});

it('closes the tablet band one pixel under the next breakpoint up', function () {
    expect(config('ui.rail_collapsed_ceiling'))->toBe(tailwindScreens()['lg'] - 1);
});

it('keeps the band a real range rather than an inverted one', function () {
    expect(config('ui.rail_collapsed_ceiling'))->toBeGreaterThan(config('ui.mobile_breakpoint'));
});
