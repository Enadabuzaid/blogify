<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Always resolves to platform-level content.
 *
 * The default, and correct for two very different situations:
 *
 *  - Single-tenant applications, where there is only one blog.
 *  - Database-per-tenant applications, where the database boundary already
 *    isolates rows and a scope would be redundant.
 */
class NullOwnerResolver implements OwnerResolver
{
    public function resolve(): ?Model
    {
        return null;
    }

    public function hasOwner(): bool
    {
        return false;
    }
}
