<?php

return [
    'groomer' => [
        'label' => 'Dog grooming',
        'subject_singular' => 'dog',
        'subject_plural' => 'dogs',
        'customer_singular' => 'client',
        'appointment_singular' => 'appointment',
        'subject_fields' => [
            ['key' => 'breed', 'label' => 'Breed', 'type' => 'text', 'required' => true],
            ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['small', 'medium', 'large', 'extra large'], 'required' => true],
            ['key' => 'coat', 'label' => 'Coat type', 'type' => 'text', 'required' => false],
            ['key' => 'notes', 'label' => 'Temperament / notes', 'type' => 'textarea', 'required' => false],
        ],
        'default_services' => [
            ['name' => 'Full groom — small dog', 'duration_minutes' => 60, 'price' => 3500, 'deposit_amount' => 1000],
            ['name' => 'Full groom — medium dog', 'duration_minutes' => 90, 'price' => 4500, 'deposit_amount' => 1000],
            ['name' => 'Bath and blow dry', 'duration_minutes' => 45, 'price' => 2500, 'deposit_amount' => 1000],
            ['name' => 'Nail clip', 'duration_minutes' => 15, 'price' => 1000, 'deposit_amount' => 0],
        ],
    ],
];
