<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the model that owns the content currently being read or written.
 *
 * A null owner means platform-level content — the blog belonging to the
 * application itself rather than to one of its tenants.
 *
 * Implementations return a Model rather than an id on purpose: the owner is
 * stored polymorphically, so the resolver has to surface the type as well as
 * the key. That also lets a ULID-keyed tenant and a bigint-keyed user own
 * content side by side in the same table.
 *
 * Implementations must be stateless. Inject dependencies via the constructor
 * and never cache request state on the instance — the resolver is bound as a
 * singleton for the lifetime of the request.
 */
interface OwnerResolver
{
    /**
     * The current owner, or null for platform-level content.
     */
    public function resolve(): ?Model;

    /**
     * Whether an owner context is currently active.
     *
     * This is not the same as `resolve() !== null`: a resolver may be active
     * (the application is running inside a tenant) while still resolving to
     * null for a specific request.
     */
    public function hasOwner(): bool;
}
