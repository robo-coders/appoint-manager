<?php

namespace App\Support;

use App\Models\Vertical;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * One vertical's own words and prices, for its trade page.
 *
 * The marketing site has exactly one vertical page live — dog grooming — and
 * is built so the second one is a route, a copy file and a row in `verticals`
 * rather than a rebuild. This class is what makes that true: everything on
 * `resources/views/marketing/partials/vertical-page.blade.php` that is specific
 * to a trade comes either from the copy array that view is given, or from here.
 * Nothing is read off `config()` for a vertical and nothing is typed into the
 * template.
 *
 * There is no `config/verticals.php` any more, which is worth stating because
 * the comments on the old marketing views still referred to one. Verticals are
 * rows in the `verticals` table, read through `Vertical::definitionFor()`, which
 * falls back to a hardcoded grooming definition when the table is empty so a
 * page still renders on a fresh database rather than throwing.
 *
 * `MarketingFigures` holds what the *whole site* may print — the price, the
 * trial, the SMS allowance. This holds what one trade page may print. The split
 * is the reason a shared partial cannot accidentally say "dog": it has no
 * access to a vertical at all.
 */
final class VerticalFigures
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(private string $key)
    {
        $this->definition = Vertical::definitionFor($key);
    }

    /** The trade's name, as the product itself spells it. `Dog grooming`. */
    public function label(): string
    {
        return (string) ($this->definition['label'] ?? 'Appointments');
    }

    /** What the appointment is *for*. `dog` for a groomer, `client` for a barber. */
    public function subject(): string
    {
        return (string) ($this->definition['subject_singular'] ?? 'subject');
    }

    /**
     * The price list a new tenant on this vertical is actually given.
     *
     * Shown on the page rather than a plausible-looking invention, which means
     * the page and the product cannot disagree about what a business starts
     * with on day one.
     *
     * @return list<array{name: string, minutes: int, price: Money, deposit: Money}>
     */
    public function priceList(): array
    {
        return array_map(fn (array $service) => [
            'name' => (string) $service['name'],
            'minutes' => (int) $service['duration_minutes'],
            'price' => new Money((int) $service['price']),
            'deposit' => new Money((int) $service['deposit_amount']),
        ], $this->services());
    }

    /**
     * The appointment a cancellation actually costs: the longest one on the
     * list, which is also the dearest on every list we seed.
     *
     * Derived rather than named, so a vertical whose price list has different
     * service names needs no code change here — that was the last thing tying
     * this arithmetic to grooming.
     *
     * @return array{name: string, duration_minutes: int, price: int, deposit_amount: int}
     */
    private function headline(): array
    {
        $services = $this->services();

        if ($services === []) {
            /*
             * Loud rather than zero. A silent fallback prints "£0.00 covers
             * £29.00" on a page whose whole argument is that subtraction, which
             * is worse than a 500 on a page nobody has reached yet.
             */
            throw new RuntimeException(sprintf('the %s vertical has an empty price list.', $this->key));
        }

        usort($services, fn (array $a, array $b) => [$b['duration_minutes'], $b['price']] <=> [$a['duration_minutes'], $a['price']]);

        /** @var array{name: string, duration_minutes: int, price: int, deposit_amount: int} */
        return $services[0];
    }

    /** The name of that appointment. `Full groom — medium dog`. */
    public function slotName(): string
    {
        return (string) $this->headline()['name'];
    }

    public function slotMinutes(): int
    {
        return (int) $this->headline()['duration_minutes'];
    }

    /** What one of them is worth. */
    public function slot(): Money
    {
        return new Money((int) $this->headline()['price']);
    }

    /** The deposit that holds it. */
    public function deposit(): Money
    {
        return new Money((int) $this->headline()['deposit_amount']);
    }

    /**
     * What is left of one refilled appointment after the month is paid for.
     *
     * Subtraction, done here, from two figures the reader can see on the page.
     * Never a hardcoded difference: a price change has to move this, or the
     * page starts lying.
     */
    public function surplus(): Money
    {
        $monthly = (int) config('billing.monthly_price_pence');

        return new Money(max(0, $this->slot()->amount - $monthly));
    }

    /** True while one refilled appointment still covers a month of software. */
    public function oneRefillCovers(): bool
    {
        return $this->slot()->amount >= (int) config('billing.monthly_price_pence');
    }

    /**
     * The extra fields this vertical's booking page asks for, as a sentence.
     *
     * "breed, size, coat type and temperament / notes" — built here rather than
     * assembled in the template, because a comma-and-"and" list written as a
     * Blade loop is four `@if`s and an off-by-one waiting to happen. The labels
     * are the vertical's own; only the case is changed, because they are
     * sentence-cased as form labels and land mid-sentence here.
     */
    public function subjectFieldList(): string
    {
        $labels = array_map(
            fn (array $field) => lcfirst((string) $field['label']),
            array_values((array) ($this->definition['subject_fields'] ?? [])),
        );

        return Arr::join($labels, ', ', ' and ');
    }

    /**
     * Whether this vertical has a price list at all.
     *
     * The template asks before drawing the two sections built on one — the
     * refill sum and the seeded price table — so a vertical with no services
     * renders a shorter page rather than a 500. A marketing page must not go
     * down because a database row is missing; it must only never invent a
     * figure, and omitting a section invents nothing.
     */
    public function hasPriceList(): bool
    {
        return $this->services() !== [];
    }

    public function hasSubjectFields(): bool
    {
        return ((array) ($this->definition['subject_fields'] ?? [])) !== [];
    }

    /**
     * @return list<array{name: string, duration_minutes: int, price: int, deposit_amount: int}>
     */
    private function services(): array
    {
        /** @var list<array{name: string, duration_minutes: int, price: int, deposit_amount: int}> */
        return array_values((array) ($this->definition['default_services'] ?? []));
    }
}
