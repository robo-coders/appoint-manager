<?php

namespace App\Services\Booking;

use App\Enums\SetupReason;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;

/**
 * What the public booking page renders: one appointment it believes in, three
 * spread ways out, and enough context to say who it thinks it is talking to.
 */
final readonly class Suggestion
{
    /**
     * @param  list<Proposal>  $alternatives
     * @param  int|null  $intervalDays  The customer's own typical gap, when they have
     *                                  one. Null for a new customer — the service's
     *                                  suggested interval was used instead.
     * @param  SetupReason|null  $setupReason  Why there is nothing to show, when the
     *                                         cause is configuration rather than a
     *                                         full diary. Null on every other path.
     */
    public function __construct(
        public ?Proposal $primary,
        public array $alternatives,
        public bool $returning,
        public ?Customer $customer,
        public ?Service $service,
        public ?Subject $subject,
        public ?int $intervalDays,
        public ?SetupReason $setupReason = null,
    ) {}

    /** Nothing is bookable inside the salon's horizon. The page offers the waitlist. */
    public function isEmpty(): bool
    {
        return $this->primary === null;
    }

    public function isSetupIncomplete(): bool
    {
        return $this->setupReason !== null;
    }

    public function state(): string
    {
        if ($this->isSetupIncomplete()) {
            return 'setup_incomplete';
        }

        return $this->primary === null ? 'fully_booked' : 'proposal';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone): array
    {
        return [
            'primary' => $this->primary?->toArray($timezone),
            'alternatives' => array_map(fn (Proposal $p) => $p->toArray($timezone), $this->alternatives),
            'returning' => $this->returning,
            'customer_name' => $this->customer?->name,
            'subject_name' => $this->subject?->name,
            'interval_days' => $this->intervalDays,
        ];
    }
}
