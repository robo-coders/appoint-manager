<?php

namespace App\BetaSandbox;

use App\Models\Tenant;

/**
 * The beta sandbox's front door. See BETA_SANDBOX.md for the file manifest.
 *
 * Everything this feature owns lives under `App\BetaSandbox`,
 * `App\Http\Controllers\BetaSandbox`, `resources/js/Components/BetaSandbox`,
 * `resources/js/Pages/BetaSandbox` and `tests/Feature/BetaSandbox`, so that
 * removing the feature is a directory delete plus the handful of integration
 * points the manifest names. Nothing beta-specific is scattered anywhere else.
 *
 * **`enabled()` is asked on the server, every time.** The Vue side hides the
 * banner and the settings tab from a tenant that is not in the beta, and that
 * is presentation only — a hidden button is not a permission. Every action goes
 * through `guard()` first, so guessing the URL gets a 404 rather than a
 * half-executed wipe.
 *
 * **404, not 403.** A tenant that is not in the beta programme should not learn
 * that a beta programme exists from an error page. The three actions and the
 * settings screen all sit behind the same answer: as far as a normal salon is
 * concerned these URLs are not routes.
 */
final class BetaSandbox
{
    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->is_beta === true;
    }

    /**
     * The tenant this request is allowed to act on, or a 404.
     *
     * Returns the tenant rather than a boolean so a caller cannot forget to use
     * the checked value and reach for an id from somewhere else — the argument
     * a controller passes in is the only tenant any sandbox action ever sees,
     * and it comes from `ResolveTenant`, which reads the session rather than the
     * request body.
     */
    public static function guard(?Tenant $tenant): Tenant
    {
        abort_unless(self::enabled($tenant), 404);

        return $tenant;
    }
}
