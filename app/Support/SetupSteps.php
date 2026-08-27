<?php

namespace App\Support;

/**
 * The five screens between "I want this" and "here is your diary".
 *
 * They live in one place because they are one flow rendered by two controllers
 * on two surfaces: `account` is signed out and belongs to
 * `RegisteredUserController`; the other four are signed in and belong to
 * `OnboardingController`. Before this, the list existed only inside
 * `OnboardingLayout.vue` and it was four items long — so the person filling in
 * the registration form was on step one of a flow that did not admit to having
 * a step one, and the progress they were shown began after they had already
 * done something.
 *
 * Named, not numbered. "3 of 5" says how much is left; "Services" says what it
 * is, and only the second helps somebody decide whether to finish now or after
 * lunch. The numbers are drawn from the order rather than stored, so the list
 * cannot be renumbered wrongly.
 *
 * The labels are here rather than in the Vue because they are customer-facing
 * copy, and this product builds those in PHP.
 */
final class SetupSteps
{
    /** The four that `Tenant::onboardingCompletedSteps()` tracks. */
    public const ONBOARDING = ['business', 'services', 'staff', 'hours'];

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'account', 'label' => 'Your account'],
            ['key' => 'business', 'label' => 'Business details'],
            ['key' => 'services', 'label' => 'Services'],
            ['key' => 'staff', 'label' => 'People'],
            ['key' => 'hours', 'label' => 'Opening hours'],
        ];
    }
}
