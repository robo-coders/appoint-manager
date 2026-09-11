<?php

use App\Enums\ThemePreference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest cannot persist appearance', function () {
    $this->patch('/appearance', ['preference' => 'dark'])->assertRedirect();
});

test('the default preference is system', function () {
    $user = User::factory()->create();

    expect($user->theme_preference)->toBe(ThemePreference::System);
});

test('the operator can persist light, dark and system', function () {
    $user = User::factory()->create();

    actingAsTenant($user)
        ->patch('/appearance', ['preference' => 'dark'])
        ->assertNoContent();

    expect($user->refresh()->theme_preference)->toBe(ThemePreference::Dark);

    actingAsTenant($user)
        ->patch('/appearance', ['preference' => 'light'])
        ->assertNoContent();

    expect($user->refresh()->theme_preference)->toBe(ThemePreference::Light);

    actingAsTenant($user)
        ->patch('/appearance', ['preference' => 'system'])
        ->assertNoContent();

    expect($user->refresh()->theme_preference)->toBe(ThemePreference::System);
});

test('an unknown preference is rejected', function () {
    $user = User::factory()->create();

    actingAsTenant($user)
        ->patch('/appearance', ['preference' => 'sepia'])
        ->assertSessionHasErrors('preference');

    expect($user->refresh()->theme_preference)->toBe(ThemePreference::System);
});

test('the preference is shared with every operator page', function () {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    actingAsTenant($user)
        ->get('/diary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.theme_preference', 'dark'));
});

test('the operator document carries the preference for the blocking script', function () {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    $html = actingAsTenant($user)->get('/diary')->assertOk()->getContent();

    expect($html)
        ->toContain('data-theme-preference="dark"')
        ->toContain('data-signed-in="1"')
        ->toContain('diarydesk.appearance')
        ->toContain('prefers-color-scheme: dark');
});

test('the login document does not apply the operator theme script', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->not->toContain('data-signed-in="1"')
        ->not->toContain('diarydesk.appearance');
});
