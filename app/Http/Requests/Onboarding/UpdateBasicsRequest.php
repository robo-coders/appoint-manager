<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Tenant;
use App\Models\Vertical;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step one: the trading name, the slug in the booking URL, the trade, and the
 * week the diary is drawn against.
 *
 * **The slug is validated here rather than generated here.** `TenantSlug` still
 * mints the first one at registration, but from this screen onwards it is a
 * field a person can see and edit, so it needs the same uniqueness rule the
 * database has — including against soft-deleted tenants, because their slugs
 * are still occupied. `ignore($tenant)` so re-saving step one without touching
 * the slug is not a collision with yourself.
 *
 * **Hours arrive as seven days, not as a flat list of ranges.** The screen is a
 * row per day with an open/closed toggle, so "closed" has to be expressible;
 * a list of ranges can only say "closed" by omission, and omission is also what
 * an empty form looks like. `open` is the discriminator, and the times are only
 * required — and only checked against each other — on the days that have it.
 */
class UpdateBasicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'lowercase',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                /*
                 * No `withoutTrashed()`, deliberately. A soft-deleted tenant
                 * still owns its slug — the booking URL it printed on a card is
                 * still out there — and `TenantSlug::generate()` skips those
                 * too. The two have to agree or the generator would hand out a
                 * suggestion this rule then rejects.
                 */
                Rule::unique(Tenant::class, 'slug')->ignore(current_tenant_id()),
            ],
            'type' => ['required', 'string', Rule::exists(Vertical::class, 'key')],

            'hours' => ['present', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'hours.*.open' => ['required', 'boolean'],
            'hours.*.start_time' => ['nullable', 'date_format:H:i'],
            'hours.*.end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'That address is already taken. Try another.',
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'hours.size' => 'Every day of the week needs an answer.',
        ];
    }

    /*
     * `open` is cast to a real boolean before anything reads it. It arrives
     * from a toggle as `true`, `1` or `"1"` depending on how the request was
     * built, and `required_if:hours.*.open,true` compares strictly against a
     * string — so the rule silently passes for a day sent as a JSON boolean.
     * Casting here means the check below can be an honest `if`.
     */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        $this->merge([
            'slug' => is_string($slug) ? strtolower(trim($slug)) : $slug,
            'hours' => collect($this->input('hours', []))->map(function ($day) {
                if (is_array($day) && array_key_exists('open', $day)) {
                    $day['open'] = filter_var($day['open'], FILTER_VALIDATE_BOOLEAN);
                }

                return $day;
            })->all(),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $days = $this->input('hours', []);
            $anyOpen = false;

            foreach ($days as $index => $day) {
                if (! ($day['open'] ?? false)) {
                    continue;
                }

                $anyOpen = true;

                if (! isset($day['start_time'], $day['end_time'])) {
                    $validator->errors()->add(
                        "hours.$index.start_time",
                        'An open day needs an opening and a closing time.',
                    );

                    continue;
                }

                if ($day['end_time'] <= $day['start_time']) {
                    $validator->errors()->add(
                        "hours.$index.end_time",
                        'Closing time must be after opening time.',
                    );
                }
            }

            /*
             * A week with every day shut is a booking page that can never take
             * a booking. It is also the state the form is in if somebody
             * toggles their way through it by accident, so it is worth a
             * sentence rather than a silent save.
             */
            if (! $anyOpen && count($days) > 0) {
                $validator->errors()->add('hours', 'Open on at least one day, or nobody can book.');
            }
        });
    }

    /**
     * The seven-day form, flattened into the `availability_rules` shape.
     *
     * One range per open day. The grid in Settings can hold several — a
     * lunch break is two ranges — but asking a new salon to describe a split
     * shift before it has taken a single booking is the sort of thing that
     * makes people close the tab.
     *
     * @return list<array{weekday: int, start_time: string, end_time: string}>
     */
    public function openDays(): array
    {
        $rules = [];

        foreach ($this->validated('hours') as $day) {
            if (! $day['open']) {
                continue;
            }

            $rules[] = [
                'weekday' => (int) $day['weekday'],
                'start_time' => $day['start_time'],
                'end_time' => $day['end_time'],
            ];
        }

        return $rules;
    }
}
