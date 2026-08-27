<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Impersonation across the surface boundary.
 *
 * A super admin's session lives on admin.{domain} and cannot be handed a cookie
 * for app.{domain} — that separation is the point of the split. So the console
 * issues a short-lived signed URL and the app surface exchanges it for a normal
 * app session tagged with `impersonator_id`.
 *
 * Both ends are audited, and the signed link is single use.
 */
class ImpersonationController extends Controller
{
    /** How long the handoff link is valid for. It is used immediately or not at all. */
    private const HANDOFF_TTL_SECONDS = 60;

    public static function handoffUrl(User $target, User $actor): string
    {
        $nonce = bin2hex(random_bytes(16));

        Cache::put(self::cacheKey($nonce), $actor->id, self::HANDOFF_TTL_SECONDS);

        return URL::temporarySignedRoute(
            'impersonation.start',
            now()->addSeconds(self::HANDOFF_TTL_SECONDS),
            ['user' => $target->id, 'nonce' => $nonce],
        );
    }

    /**
     * Exchange the signed link for an app session. The signature proves the
     * console issued it; the nonce makes it single use; and the actor is
     * re-checked for super admin here, not only when the link was made.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        $nonce = (string) $request->query('nonce', '');
        $actorId = Cache::pull(self::cacheKey($nonce));

        abort_if($actorId === null, 403, 'This impersonation link has already been used.');

        $actor = User::withoutGlobalScopes()->find($actorId);

        abort_unless($actor?->is_super_admin, 403);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $actor->id);

        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'target_tenant_id' => $user->tenant_id,
            'target_user_id' => $user->id,
            'action' => 'impersonate.start',
        ]);

        return redirect()->to(Surface::App->to('diary'));
    }

    /**
     * End impersonation. The app session is destroyed; the console session on
     * admin.{domain} was never touched and is still valid, so the super admin
     * simply lands back on it.
     *
     * `Inertia::location`, not `redirect()->away` — and this was logged in
     * DECISIONS.md as broken before it was fixed here.
     *
     * The control that calls this is inside the tenant's app, so the request is
     * an Inertia visit followed by XHR. A plain redirect is followed by the
     * Inertia client, which then receives an HTML document for a **different
     * origin** that it has no page component for. In subdomain mode that is a
     * cross-origin XHR the browser refuses outright; without subdomains it
     * paints the console *inside* the salon's shell, so the rail of the tenant
     * you have just stopped impersonating stays on screen. Either way the one
     * escape hatch from someone else's session did not work.
     *
     * `Inertia::location` answers 409 with `X-Inertia-Location`, which the
     * client turns into a real `window.location` visit — a full page load, which
     * is also what we want after destroying a session. Exactly the fix
     * `AuthenticatedSessionController::destroy` documents for logout, which is
     * the same bug on the same seam.
     */
    public function stop(Request $request): HttpResponse
    {
        $actorId = $request->session()->get('impersonator_id');

        abort_unless($actorId, 403);

        AuditLog::query()->create([
            'actor_id' => $actorId,
            'target_tenant_id' => $request->user()?->tenant_id,
            'target_user_id' => $request->user()?->id,
            'action' => 'impersonate.stop',
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(Surface::Admin->to());
    }

    private static function cacheKey(string $nonce): string
    {
        return 'impersonation-handoff:'.$nonce;
    }
}
