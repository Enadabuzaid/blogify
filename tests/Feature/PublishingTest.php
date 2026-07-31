<?php

declare(strict_types=1);

use Enadstack\Blogify\Enums\PostStatus;
use Enadstack\Blogify\Events\PostDeleted;
use Enadstack\Blogify\Events\PostPublished;
use Enadstack\Blogify\Events\PostUnpublished;
use Enadstack\Blogify\Events\TermCreated;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\Term;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

describe('published scope', function () {
    it('excludes drafts, scheduled and archived posts', function () {
        Post::query()->create(['status' => PostStatus::Draft->value]);
        Post::query()->create(['status' => PostStatus::Archived->value]);
        Post::query()->create(['status' => PostStatus::Scheduled->value, 'published_at' => now()->addDay()]);
        Post::query()->create(['status' => PostStatus::Published->value, 'published_at' => now()->subDay()]);

        expect(Post::query()->published()->count())->toBe(1);
    });

    /*
     * A row can sit at 'published' with a future date if an editor promoted it by
     * hand rather than letting the scheduler do it, so the scope has to check the
     * date as well as the status.
     */
    it('excludes a published post whose date has not arrived', function () {
        Post::query()->create([
            'status' => PostStatus::Published->value,
            'published_at' => now()->addWeek(),
        ]);

        expect(Post::query()->published()->count())->toBe(0);
    });

    it('includes a published post with no date at all', function () {
        Post::query()->create(['status' => PostStatus::Published->value]);

        expect(Post::query()->published()->count())->toBe(1);
    });
});

describe('blogify:publish-scheduled', function () {
    it('promotes scheduled posts whose date has arrived', function () {
        $due = Post::query()->create([
            'status' => PostStatus::Scheduled->value,
            'published_at' => now()->subMinute(),
        ]);

        $notDue = Post::query()->create([
            'status' => PostStatus::Scheduled->value,
            'published_at' => now()->addDay(),
        ]);

        $this->artisan('blogify:publish-scheduled')->assertSuccessful();

        expect($due->fresh()->status)->toBe(PostStatus::Published)
            ->and($notDue->fresh()->status)->toBe(PostStatus::Scheduled);
    });

    /*
     * The command runs from the scheduler with no owner context. Without
     * allOwners() the global scope would restrict it to platform content and
     * leave every tenant's scheduled posts stuck in the queue forever — which is
     * the kind of bug that only shows up in production.
     */
    it('publishes across every owner, not just the platform', function () {
        $tenant = $this->makeTenant('Clinic A');

        $this->actingForOwner($tenant);
        $tenantPost = Post::query()->create([
            'status' => PostStatus::Scheduled->value,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingForOwner(null);
        $platformPost = Post::query()->create([
            'status' => PostStatus::Scheduled->value,
            'published_at' => now()->subMinute(),
        ]);

        $this->artisan('blogify:publish-scheduled')->assertSuccessful();

        expect(Post::query()->allOwners()->find($tenantPost->getKey())->status)
            ->toBe(PostStatus::Published)
            ->and(Post::query()->allOwners()->find($platformPost->getKey())->status)
            ->toBe(PostStatus::Published);
    });

    it('fires PostPublished for each promoted post', function () {
        Event::fake([PostPublished::class]);

        Post::query()->create([
            'status' => PostStatus::Scheduled->value,
            'published_at' => now()->subMinute(),
        ]);

        $this->artisan('blogify:publish-scheduled')->assertSuccessful();

        Event::assertDispatched(PostPublished::class, 1);
    });

    it('reports when nothing was due', function () {
        $this->artisan('blogify:publish-scheduled')
            ->expectsOutputToContain('No scheduled posts were due.')
            ->assertSuccessful();
    });
});

describe('lifecycle events', function () {
    it('fires PostPublished when a post is created published', function () {
        Event::fake([PostPublished::class, PostUnpublished::class]);

        Post::query()->create(['status' => PostStatus::Published->value]);

        Event::assertDispatched(PostPublished::class);
        Event::assertNotDispatched(PostUnpublished::class);
    });

    it('stays quiet when a draft is created', function () {
        Event::fake([PostPublished::class, PostUnpublished::class]);

        Post::query()->create(['status' => PostStatus::Draft->value]);

        Event::assertNotDispatched(PostPublished::class);
        Event::assertNotDispatched(PostUnpublished::class);
    });

    it('fires PostPublished on the draft to published transition', function () {
        $post = Post::query()->create(['status' => PostStatus::Draft->value]);

        Event::fake([PostPublished::class]);

        $post->status = PostStatus::Published;
        $post->save();

        Event::assertDispatched(PostPublished::class);
    });

    it('fires PostUnpublished when a live post is archived', function () {
        $post = Post::query()->create(['status' => PostStatus::Published->value]);

        Event::fake([PostUnpublished::class]);

        $post->status = PostStatus::Archived;
        $post->save();

        Event::assertDispatched(PostUnpublished::class);
    });

    it('stays quiet when an unrelated attribute changes', function () {
        $post = Post::query()->create(['status' => PostStatus::Published->value]);

        Event::fake([PostPublished::class, PostUnpublished::class]);

        $post->featured = true;
        $post->save();

        Event::assertNotDispatched(PostPublished::class);
        Event::assertNotDispatched(PostUnpublished::class);
    });

    it('distinguishes a soft delete from a hard one', function () {
        $post = Post::query()->create(['status' => PostStatus::Published->value]);

        Event::fake([PostDeleted::class]);

        $post->delete();
        Event::assertDispatched(fn (PostDeleted $e): bool => $e->forced === false);

        $post->forceDelete();
        Event::assertDispatched(fn (PostDeleted $e): bool => $e->forced === true);
    });

    it('fires TermCreated once per term', function () {
        Event::fake([TermCreated::class]);

        Term::query()->create(['taxonomy' => 'category']);

        Event::assertDispatched(TermCreated::class, 1);
    });
});

describe('scheduling', function () {
    it('does not register the scheduled command when the cron is null', function () {
        config()->set('blogify.schedule.publish_cron', null);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'blogify:publish-scheduled'));

        expect($events)->toBeEmpty();
    });
});
