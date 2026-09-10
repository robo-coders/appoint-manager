<?php

namespace App\Support;

/**
 * The six screens between "I want this" and "here is your diary".
 *
 * They live in one place because they are one flow rendered by two controllers
 * on two surfaces: `account` is signed out and belongs to
 * `RegisteredUserController`; the other five are signed in and belong to
 * `OnboardingController`. Before this, the list existed only in the Vue and it
 * was four items long — so the person filling in the registration form was on
 * step one of a flow that did not admit to having a step one, and the progress
 * they were shown began after they had already done something.
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
    /**
     * The five that `Tenant::onboardingCompletedSteps()` tracks, in order.
     *
     * `basics` opens the flow because it owns the two things the booking page
     * cannot exist without — the trading name and the slug in its URL — plus
     * the week the diary is drawn against. Registration collects the first two,
     * so this step is usually a confirmation rather than a form, which is the
     * right thing for a first screen to be.
     *
     * `link` closes it, and is the step whose completion sets
     * `onboarding_completed_at`. Before this the flow ended on `hours`, which
     * meant the last thing a new salon did was fill in a grid; now it is
     * handed the URL it is going to spend the next year sending to people.
     */
    public const ONBOARDING = ['basics', 'business', 'services', 'staff', 'link'];

    /** The step that, once saved, finishes onboarding. */
    public const FINAL = 'link';

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'account', 'label' => 'Your account'],
            ['key' => 'basics', 'label' => 'Business basics'],
            ['key' => 'business', 'label' => 'Business details'],
            ['key' => 'services', 'label' => 'First service'],
            ['key' => 'staff', 'label' => 'People'],
            ['key' => 'link', 'label' => 'Booking link'],
        ];
    }
}
