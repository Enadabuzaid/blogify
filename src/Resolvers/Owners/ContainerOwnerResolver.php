<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads the current owner from a container binding.
 *
 * This suits the common hand-rolled pattern where a middleware resolves the
 * tenant once per request and shares it through the container:
 *
 *     app()->instance('currentTenant', $tenant);
 *
 * The binding key comes from config('blogify.tenancy.container_key'). Nothing
 * is assumed about how the binding gets set, so the same resolver works for a
 * dashboard route that resolves by route parameter and a public route that
 * resolves by HTTP host.
 *
 * If the binding is absent the resolver reports no owner rather than throwing,
 * because the platform's own pages legitimately run outside tenant context.
 */
class ContainerOwnerResolver implements OwnerResolver
{
    public function resolve(): ?Model
    {
        $key = $this->key();

        if (! app()->bound($key)) {
            return null;
        }

        $owner = app($key);

        return $owner instanceof Model ? $owner : null;
    }

    public function hasOwner(): bool
    {
        return $this->resolve() !== null;
    }

    protected function key(): string
    {
        return (string) config('blogify.tenancy.container_key', 'currentTenant');
    }
}
