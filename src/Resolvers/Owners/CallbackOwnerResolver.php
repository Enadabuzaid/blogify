<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Blogify;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Defers to a callback registered at runtime.
 *
 * The escape hatch for resolution logic the shipped resolvers cannot express —
 * a public site that resolves the owner from the HTTP host, say, or one that
 * has to try several sources in order:
 *
 *     // in a service provider's boot()
 *     Blogify::resolveOwnerUsing(function () {
 *         return app()->bound('currentTenant')
 *             ? app('currentTenant')
 *             : $this->resolveTenantFromHost(request()->getHost());
 *     });
 *
 * The callback lives on the Blogify class rather than in the config file
 * because a closure in config breaks `php artisan config:cache`.
 */
class CallbackOwnerResolver implements OwnerResolver
{
    public function resolve(): ?Model
    {
        $callback = Blogify::ownerCallback();

        if ($callback === null) {
            return null;
        }

        $owner = $callback();

        return $owner instanceof Model ? $owner : null;
    }

    public function hasOwner(): bool
    {
        return $this->resolve() !== null;
    }
}
