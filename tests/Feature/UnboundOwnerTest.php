<?php

declare(strict_types=1);

use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Model;

/*
 * Writing owned content in `shared` mode while NO owner is resolved.
 *
 * This is the situation in every artisan command, queued job and scheduler run,
 * and it is the one place the global scope actively works against you: a model
 * reaching for its parent through a normal relation gets OwnerScope applied,
 * matches the platform sentinel instead of the row's real owner, and silently
 * finds nothing.
 *
 * That was a real bug. Translations inherit `owner_key` (and terms also inherit
 * `taxonomy`) from their parent, and when the parent lookup returned null those
 * columns stayed unset — a NOT NULL violation on term translations, and worse on
 * posts, where the column default would have quietly filed a tenant's
 * translation under the platform.
 */

beforeEach(function () {
    // Shared mode with a resolver that never resolves anything — exactly what a
    // command sees.
    config()->set('blogify.tenancy.mode', 'shared');

    $this->app->instance(OwnerResolver::class, new class implements OwnerResolver
    {
        public function resolve(): ?Model
        {
            return null;
        }

        public function hasOwner(): bool
        {
            return false;
        }
    });
});

it('inherits the owner key onto a post translation', function () {
    $tenant = $this->makeTenant('Clinic A');

    $post = new Post(['type' => 'post']);
    $post->assignOwner($tenant);
    $post->save();

    $post->setTranslation('en', ['title' => 'Written By A Command']);

    $translation = $post->translations()->first();

    expect($translation->owner_key)->toBe(OwnerKey::for($tenant))
        ->and($translation->owner_key)->not->toBe(OwnerKey::PLATFORM);
});

it('inherits owner key and taxonomy onto a term translation', function () {
    $tenant = $this->makeTenant('Clinic A');

    $term = new Term(['taxonomy' => 'category']);
    $term->assignOwner($tenant);
    $term->save();

    $term->setTranslation('ar', ['name' => 'صحة النوم']);

    $translation = $term->translations()->first();

    expect($translation->owner_key)->toBe(OwnerKey::for($tenant))
        ->and($translation->taxonomy)->toBe('category')
        ->and($translation->slug)->toBe('صحة-النوم');
});

it('still de-duplicates slugs within the right owner', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    foreach ([$a, $a, $b] as $owner) {
        $post = new Post(['type' => 'post']);
        $post->assignOwner($owner);
        $post->save();
        $post->setTranslation('en', ['title' => 'About Us']);
    }

    // Two posts for A collide and the second gets a suffix; B is a separate
    // scope and keeps the clean slug.
    expect(Post::query()->forOwner($a)->get()->map->slug('en')->sort()->values()->all())
        ->toBe(['about-us', 'about-us-2'])
        ->and(Post::query()->forOwner($b)->first()->slug('en'))->toBe('about-us');
});

it('keeps reading time up to date', function () {
    config()->set('blogify.content.words_per_minute', 10);

    $tenant = $this->makeTenant('Clinic A');

    $post = new Post(['type' => 'post']);
    $post->assignOwner($tenant);
    $post->save();

    $post->setTranslation('en', ['title' => 'Long Read', 'body' => str_repeat('word ', 50)]);

    expect(Post::query()->forOwner($tenant)->first()->reading_time)->toBe(5);
});

it('records slug history against the right owner', function () {
    $tenant = $this->makeTenant('Clinic A');

    $post = new Post(['type' => 'post']);
    $post->assignOwner($tenant);
    $post->save();
    $post->setTranslation('en', ['title' => 'Original Title']);

    $translation = $post->translations()->first();
    $translation->title = 'Renamed Title';
    $translation->slug = null;
    $translation->save();

    expect($post->slugHistory()->first()->owner_key)->toBe(OwnerKey::for($tenant));
});

it('shows a post its own terms with no owner bound', function () {
    $tenant = $this->makeTenant('Clinic A');

    $post = new Post(['type' => 'post']);
    $post->assignOwner($tenant);
    $post->save();

    $term = new Term(['taxonomy' => 'category']);
    $term->assignOwner($tenant);
    $term->save();
    $term->setTranslation('en', ['name' => 'Guides']);

    $post->terms()->attach($term->getKey());

    expect($post->fresh()->terms)->toHaveCount(1)
        ->and($post->fresh()->terms->first()->name('en'))->toBe('Guides');
});

/*
 * The platform defines shared categories that tenants attach to their own posts.
 * An owner scope on the pivot would hide exactly those.
 */
it('shows a tenant post a platform-owned term', function () {
    $tenant = $this->makeTenant('Clinic A');

    $shared = new Term(['taxonomy' => 'category']);
    $shared->assignOwner(null);
    $shared->save();
    $shared->setTranslation('en', ['name' => 'Announcements']);

    $post = new Post(['type' => 'post']);
    $post->assignOwner($tenant);
    $post->save();
    $post->terms()->attach($shared->getKey());

    // Now with the tenant bound, which is when the scope would have bitten.
    $this->actingForOwner($tenant);

    expect(Post::query()->first()->terms)->toHaveCount(1)
        ->and(Post::query()->first()->terms->first()->isPlatformContent())->toBeTrue();
});
