<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Events;

use Enadstack\Blogify\Models\Term;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A taxonomy term was created.
 *
 * Useful for invalidating a cached navigation tree, or for mirroring a tenant's
 * new category into a search facet.
 */
class TermCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Term $term) {}
}
