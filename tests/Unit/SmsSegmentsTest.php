<?php

use App\Support\SmsSegments;

it('counts a short plain message as one segment', function () {
    expect(SmsSegments::count('Willow Street: Bella is due 30 August.'))->toBe(1)
        ->and(SmsSegments::encoding('Willow Street: Bella is due 30 August.'))->toBe('GSM-7');
});

it('counts exactly 160 GSM-7 characters as one segment and 161 as two', function () {
    expect(SmsSegments::count(str_repeat('a', 160)))->toBe(1)
        ->and(SmsSegments::count(str_repeat('a', 161)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 306)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 307)))->toBe(3);
});

it('drops the limit to 70 when one character is outside GSM-7', function () {
    // A curly apostrophe is the commonest way this happens by accident; an
    // accented name is the commonest way it happens legitimately.
    $accented = 'Zoë';

    expect(SmsSegments::isGsm7($accented))->toBeFalse()
        ->and(SmsSegments::encoding($accented))->toBe('UCS-2')
        ->and(SmsSegments::count(str_repeat('a', 69).'ë'))->toBe(1)
        ->and(SmsSegments::count(str_repeat('a', 70).'ë'))->toBe(2);
});

it('treats the GSM-7 extension characters as two septets each', function () {
    // '[' is in the extension table: it is sent as escape plus character, so
    // eighty of them fill a segment rather than 160.
    expect(SmsSegments::count(str_repeat('[', 80)))->toBe(1)
        ->and(SmsSegments::count(str_repeat('[', 81)))->toBe(2);
});

it('counts an emoji as two UCS-2 units', function () {
    // Outside the BMP, so a surrogate pair.
    expect(SmsSegments::count(str_repeat('a', 68).'🐕'))->toBe(1)
        ->and(SmsSegments::count(str_repeat('a', 69).'🐕'))->toBe(2);
});

it('keeps a GSM-7 accented character inside GSM-7', function () {
    // é, ü, à and £ really are in GSM 03.38, and treating them as UCS-2 would
    // halve the budget of a perfectly ordinary message.
    expect(SmsSegments::isGsm7('Café £5 Müller à la'))->toBeTrue();
});

it('straightens curly punctuation so a message does not silently double', function () {
    $curly = "Sam\u{2019}s Grooming \u{2014} Bella\u{00A0}is due\u{2026}";
    $straight = SmsSegments::sanitise($curly);

    expect($straight)->toBe("Sam's Grooming - Bella is due...")
        ->and(SmsSegments::isGsm7($curly))->toBeFalse()
        ->and(SmsSegments::isGsm7($straight))->toBeTrue();
});

it('never strips an accent from a name to save a segment', function () {
    expect(SmsSegments::sanitise('Zoë Bergström'))->toBe('Zoë Bergström');
});

it('shortens the named part rather than the tail when a message will not fit', function () {
    $url = 'https://book.example.test/a-salon';
    $render = fn (string $salon): string => $salon.': Bella is due 30 August. Book: '.$url.' Reply STOP to opt out.';

    $fitted = SmsSegments::fit(str_repeat('Long Salon Name ', 40), $render, 1);

    expect(SmsSegments::count($fitted))->toBe(1)
        // The link and the opt-out survive, which is the whole point. Character
        // truncation cut both off the end.
        ->and($fitted)->toEndWith($url.' Reply STOP to opt out.');
});

it('returns the message untouched when it already fits', function () {
    $render = fn (string $salon): string => $salon.': Bella is due.';

    expect(SmsSegments::fit('Willow Street', $render, 1))->toBe('Willow Street: Bella is due.');
});

it('describes a body for the dry run', function () {
    $shape = SmsSegments::describe(str_repeat('a', 200));

    expect($shape['segments'])->toBe(2)
        ->and($shape['encoding'])->toBe('GSM-7')
        ->and($shape['characters'])->toBe(200)
        ->and($shape['remaining'])->toBe(106);
});
