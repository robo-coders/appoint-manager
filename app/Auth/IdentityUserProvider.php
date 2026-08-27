<?php

namespace App\Auth;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a user is looked up before anybody knows their tenant.
 *
 * Logging in, restoring a session from its cookie, a remember-me token and a
 * password reset all have to find a person by a credential — an email address,
 * a session id — at a moment when there is no tenant context to scope by, and
 * there cannot be: the context is derived *from* the user that has not been
 * found yet.
 *
 * `User` used to answer this by overriding `tenantScopeFailClosed()` to false,
 * which turned every `User` query in the application into a cross-tenant one:
 * route bindings, console commands, anything. That is a class-wide hole opened
 * for four methods. It lives here instead, where the four methods are.
 *
 * `newModelQuery()` is the single choke point — `retrieveById`,
 * `retrieveByToken` and `retrieveByCredentials` all read through it. Writes
 * (`updateRememberToken`, the rehash) go through `Model::save()`, which builds
 * its update query with no global scopes anyway.
 */
class IdentityUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
