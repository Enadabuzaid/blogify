<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Resolvers\Owners;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the owner from the authenticated user.
 *
 * Reads the relation named by config('blogify.tenancy.user_relation') — a
 * 'tenant', 'lawyer' or 'clinic' relation, depending on the application. When
 * that relation is absent or null and `fallback_to_user` is enabled, the user
 * itself becomes the owner, which is what a single-author-per-account product
 * wants.
 *
 * Note this reads the relation, not a foreign key column: the owner has to be
 * a Model so its morph class can be stored alongside its key.
 */
class AuthOwnerResolver implements OwnerResolver
{
    public function resolve(): ?Model
    {
        $user = auth()->user();

        if (! $user instanceof Model) {
            return null;
        }

        $relation = (string) config('blogify.tenancy.user_relation', 'tenant');

        if ($relation !== '' && $this->canRead($user, $relation)) {
            $owner = $user->getAttribute($relation);

            if ($owner instanceof Model) {
                return $owner;
            }
        }

        return config('blogify.tenancy.fallback_to_user', false) ? $user : null;
    }

    public function hasOwner(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Whether the relation or attribute can be read without blowing up.
     *
     * A missing Eloquent relation raises a BadMethodCallException on access,
     * so check for the relation method or a loaded attribute first.
     */
    protected function canRead(Model $user, string $relation): bool
    {
        return method_exists($user, $relation)
            || $user->relationLoaded($relation)
            || array_key_exists($relation, $user->getAttributes());
    }
}
