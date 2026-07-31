<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Events;

use Enadstack\Blogify\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A post became publicly visible.
 *
 * Listen for this to warm caches, ping a sitemap, or notify subscribers.
 */
class PostPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Post $post) {}
}
