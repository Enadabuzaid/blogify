<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Console;

use Enadstack\Blogify\Enums\PostStatus;
use Enadstack\Blogify\Events\PostPublished;
use Enadstack\Blogify\Models\Post;
use Illuminate\Console\Command;

class PublishScheduledCommand extends Command
{
    protected $signature = 'blogify:publish-scheduled';

    protected $description = 'Publish scheduled posts whose publish date has arrived';

    public function handle(): int
    {
        /** @var class-string<Post> $model */
        $model = config('blogify.models.post', Post::class);

        $published = 0;

        // allOwners() is essential here: the command runs from the scheduler
        // with no owner context, so the global scope would otherwise restrict it
        // to platform content and leave every tenant's posts unpublished.
        $model::query()
            ->allOwners()
            ->due()
            ->chunkById(200, function ($posts) use (&$published): void {
                foreach ($posts as $post) {
                    $post->forceFill(['status' => PostStatus::Published->value])->saveQuietly();

                    event(new PostPublished($post));

                    $published++;
                }
            });

        $this->components->info(
            $published === 0
                ? 'No scheduled posts were due.'
                : "Published {$published} scheduled post(s)."
        );

        return self::SUCCESS;
    }
}
