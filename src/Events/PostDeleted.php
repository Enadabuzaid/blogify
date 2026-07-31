<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Events;

use Enadstack\Blogify\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A post was deleted.
 *
 * Fired for a soft delete as well as a hard one; $forced distinguishes them, so
 * a listener that purges media or search-index entries can tell a reversible
 * removal from a permanent one.
 */
class PostDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Post $post,
        public readonly bool $forced = false,
    ) {}
}
