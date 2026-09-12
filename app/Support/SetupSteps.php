<?php

namespace App\Support;

final class SetupSteps
{
    public const ONBOARDING = ['basics', 'business', 'services', 'link'];

    public const FINAL = 'link';

    /** @return list<array{key: string, label: string}> */
    public static function all(): array
    {
        return [
            ['key' => 'account', 'label' => 'Your account'],
            ['key' => 'basics', 'label' => 'Business basics'],
            ['key' => 'business', 'label' => 'Business details'],
            ['key' => 'services', 'label' => 'First service'],
            ['key' => 'link', 'label' => 'Booking link'],
        ];
    }
}
