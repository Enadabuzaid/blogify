<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Integrates with spatie/laravel-multitenancy without depending on it.
 *
 * Reads Spatie's current tenant. Guarded by class_exists() so the class can be
 * referenced safely in config and tests regardless of whether the package is
 * installed; resolves to platform-level when it is not.
 */
class SpatieOwnerResolver implements OwnerResolver
{
    protected const TENANT = '\Spatie\Multitenancy\Models\Tenant';

    public function resolve(): ?Model
    {
        if (! self::isAvailable()) {
            return null;
        }

        /** @var mixed $tenant */
        $tenant = call_user_func([self::TENANT, 'current']);

        return $tenant instanceof Model ? $tenant : null;
    }

    public function hasOwner(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Whether spatie/laravel-multitenancy is installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::TENANT) && is_callable([self::TENANT, 'current']);
    }
}
