<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Events;

use Enadstack\Blogify\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A post stopped being publicly visible — drafted, archived, or soft-deleted.
 *
 * The counterpart to PostPublished: anything that reacted to publication
 * (caches, sitemaps, feeds) usually needs to react to this too.
 */
class PostUnpublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Post $post) {}
}
