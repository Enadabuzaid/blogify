<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Exceptions;

use RuntimeException;

/**
 * Thrown when a record would be written with no owner while
 * blogify.tenancy.require_owner is enabled in shared mode.
 *
 * Opt-in strictness: it turns "the resolver quietly returned null and this row
 * silently became platform content" into a loud failure at the point of the
 * bug, which is usually a missing middleware or an unbound container key.
 */
class OwnerRequired extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Cannot create [{$model}] without an owner: blogify.tenancy.require_owner "
            .'is enabled but the configured resolver returned null. Either resolve an '
            .'owner for this request, assign one explicitly with assignOwner(), or '
            .'disable require_owner if the platform also publishes content.'
        );
    }
}
