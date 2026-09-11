<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CalendarFeedToken;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalendarFeedTokenService;
use App\Services\IcalFeedBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalendarSyncController extends Controller
{
    public function __construct(private readonly CalendarFeedTokenService $tokens) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();

        return Inertia::render('Settings/CalendarSync/Index', [
            'feeds' => $this->feeds($tenant, (int) $request->user()->getKey()),
            'salonFeed' => $this->salonFeed($tenant),
            'contentMode' => $this->contentMode($tenant),
            'verticalNoun' => (string) ($tenant->vertical()['business_noun'] ?? 'salon'),
            'sampleService' => $this->sampleService($tenant),
            'firstSyncHours' => (int) config('calendar_sync.first_sync_note_hours'),
        ]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'scope' => ['required', Rule::in(CalendarFeedToken::scopes())],
            'staff_id' => [
                'required_if:scope,'.CalendarFeedToken::SCOPE_STAFF,
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenant->getKey()),
            ],
        ]);

        $scope = (string) $data['scope'];

        $this->tokens->regenerate(
            $tenant,
            $scope,
            $scope === CalendarFeedToken::SCOPE_STAFF ? (int) $data['staff_id'] : null,
        );

        return back();
    }

    public function updateContentMode(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'mode' => ['required', Rule::in(IcalFeedBuilder::modes())],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['calendar_feed_content_mode'] = $data['mode'];

        $tenant->forceFill(['settings' => $settings])->save();

        return back();
    }

    private function tenant(): Tenant
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);
        abort_unless(request()->user()?->isOwner(), 403);

        return $tenant;
    }

    /** @return list<array<string, mixed>> */
    private function feeds(Tenant $tenant, int $viewerId): array
    {
        return $this->bookableStaff()
            ->map(function (User $person) use ($tenant, $viewerId): array {
                $token = $this->tokens->ensure($tenant, CalendarFeedToken::SCOPE_STAFF, (int) $person->getKey());

                return [
                    'staffId' => (int) $person->getKey(),
                    'staffName' => (string) $person->name,
                    'role' => Str::ucfirst($person->role->value),
                    'url' => $this->url($tenant, $token),
                    'lastPulledAt' => $this->pulledAt($token),
                    'isOwn' => (int) $person->getKey() === $viewerId,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function salonFeed(Tenant $tenant): array
    {
        $token = $this->tokens->ensure($tenant, CalendarFeedToken::SCOPE_SALON);

        return [
            'url' => $this->url($tenant, $token),
            'lastPulledAt' => $this->pulledAt($token),
        ];
    }

    /** @return Collection<int, User> */
    private function bookableStaff(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('role', UserRole::Staff)
                ->orWhere('is_bookable', true))
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    private function url(Tenant $tenant, CalendarFeedToken $token): string
    {
        return route('ical.feed', ['tenantSlug' => $tenant->slug, 'token' => $token->token]);
    }

    private function pulledAt(CalendarFeedToken $token): ?string
    {
        return $token->last_pulled_at?->diffForHumans();
    }

    private function sampleService(Tenant $tenant): string
    {
        $name = Service::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('name');

        return $name !== null
            ? (string) $name
            : Str::ucfirst((string) ($tenant->vertical()['appointment_singular'] ?? 'appointment'));
    }

    private function contentMode(Tenant $tenant): string
    {
        $mode = (string) data_get($tenant->settings, 'calendar_feed_content_mode', IcalFeedBuilder::MODE_FULL);

        return in_array($mode, IcalFeedBuilder::modes(), true) ? $mode : IcalFeedBuilder::MODE_FULL;
    }
}
