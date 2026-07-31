<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Integrates with stancl/tenancy without depending on it.
 *
 * stancl/tenancy is not a requirement of this package, so everything here is
 * guarded by class_exists() and the resolver degrades to platform-level rather
 * than throwing when the package is absent.
 *
 * Worth knowing before reaching for this: in a database-per-tenant stancl
 * setup, each tenant already has its own database, so `isolated` mode plus
 * NullOwnerResolver is usually the better fit. This resolver is for the
 * single-database stancl configurations, and for applications that keep
 * platform content in the central database alongside tenant rows.
 */
class StanclOwnerResolver implements OwnerResolver
{
    public function resolve(): ?Model
    {
        if (! function_exists('tenant')) {
            return null;
        }

        /** @var mixed $tenant */
        $tenant = tenant();

        return $tenant instanceof Model ? $tenant : null;
    }

    public function hasOwner(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Whether stancl/tenancy is installed and initialised.
     */
    public static function isAvailable(): bool
    {
        return class_exists('\Stancl\Tenancy\Tenancy') && function_exists('tenant');
    }
}
