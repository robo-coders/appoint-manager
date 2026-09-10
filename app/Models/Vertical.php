<?php

namespace App\Models;

use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'business_noun',
        'subject_singular',
        'subject_plural',
        'customer_singular',
        'appointment_singular',
        'subject_fields',
        'default_services',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_fields' => 'array',
            'default_services' => 'array',
        ];
    }

    /**
     * The array `Tenant::vertical()` and the shared Inertia prop have always
     * returned. Same keys as the old config file, so a missing type still
     * degrades to groomer rather than to a page that cannot name its subject.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => $this->label,
            'business_noun' => $this->business_noun,
            'subject_singular' => $this->subject_singular,
            'subject_plural' => $this->subject_plural,
            'customer_singular' => $this->customer_singular,
            'appointment_singular' => $this->appointment_singular,
            'subject_fields' => $this->subject_fields ?? [],
            'default_services' => $this->default_services ?? [],
        ];
    }

    /**
     * The one line under this trade wherever it is offered as a choice.
     *
     * It is the vertical's own vocabulary — "dogs · per visit", "clients only" —
     * because what actually differs between these options is the words the
     * product will use afterwards, and a person recognises their trade by those
     * faster than by a description of it.
     *
     * On the model rather than in a controller because two screens ask the
     * question: `/register` collects the trade and `/onboarding` confirms it,
     * and the second was written first with this as a private method of
     * `OnboardingController`. A note that differed between the screen that
     * takes the answer and the screen that shows it back would be two answers.
     */
    public function note(): string
    {
        $definition = $this->definition();

        return count($definition['subject_fields'] ?? []) > 0
            ? Str::lower($definition['subject_plural']).' · per visit'
            : Str::lower($definition['customer_singular']).'s only';
    }

    /**
     * @return array<string, mixed>
     */
    public static function definitionFor(?string $key): array
    {
        $vertical = filled($key)
            ? static::query()->where('key', $key)->first()
            : null;

        $vertical ??= static::query()->where('key', 'groomer')->first();

        return $vertical?->definition() ?? [
            'label' => 'Dog grooming',
            'business_noun' => 'salon',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
            'customer_singular' => 'client',
            'appointment_singular' => 'appointment',
            'subject_fields' => [],
            'default_services' => [],
        ];
    }
}
