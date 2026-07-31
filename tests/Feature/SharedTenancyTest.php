<?php

declare(strict_types=1);

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\OwnerKey;

it('stamps the resolved owner on create', function () {
    $tenant = $this->makeTenant('Clinic A');
    $this->actingForOwner($tenant);

    $post = Post::query()->create(['type' => 'post']);

    expect($post->owner_type)->toBe($tenant->getMorphClass())
        ->and($post->owner_id)->toBe((string) $tenant->getKey())
        ->and($post->owner_key)->toBe(OwnerKey::for($tenant))
        ->and($post->isPlatformContent())->toBeFalse();
});

it('hides one owner\'s posts from another', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);
    Post::query()->create(['type' => 'post']);
    Post::query()->create(['type' => 'post']);

    $this->actingForOwner($b);
    Post::query()->create(['type' => 'post']);

    expect(Post::query()->count())->toBe(1);

    $this->actingForOwner($a);
    expect(Post::query()->count())->toBe(2);
});

/*
 * The visibility decision, asserted rather than assumed.
 *
 * A tenant's blog shows only their own posts. Platform content is not folded in,
 * because a clinic's blog surfacing the platform's marketing articles is
 * surprising rather than useful.
 */
it('hides platform posts from a tenant', function () {
    $this->actingForOwner(null);
    Post::query()->create(['type' => 'post']);

    $tenant = $this->makeTenant('Clinic A');
    $this->actingForOwner($tenant);
    Post::query()->create(['type' => 'post']);

    expect(Post::query()->count())->toBe(1)
        ->and(Post::query()->first()->owner_key)->toBe(OwnerKey::for($tenant));
});

it('hides tenant posts from the platform', function () {
    $tenant = $this->makeTenant('Clinic A');
    $this->actingForOwner($tenant);
    Post::query()->create(['type' => 'post']);

    $this->actingForOwner(null);
    Post::query()->create(['type' => 'post']);

    expect(Post::query()->count())->toBe(1)
        ->and(Post::query()->first()->isPlatformContent())->toBeTrue();
});

it('reaches every owner through allOwners', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);
    Post::query()->create(['type' => 'post']);
    $this->actingForOwner($b);
    Post::query()->create(['type' => 'post']);
    $this->actingForOwner(null);
    Post::query()->create(['type' => 'post']);

    $this->actingForOwner($a);

    expect(Post::query()->allOwners()->count())->toBe(3)
        ->and(Post::query()->platform()->count())->toBe(1)
        ->and(Post::query()->ownedByAnyone()->count())->toBe(2)
        ->and(Post::query()->forOwner($b)->count())->toBe(1);
});

/*
 * The mixed-key-type case that motivated string morph columns.
 *
 * TestTenant is ULID-keyed and TestUser is bigint-keyed. Laravel's
 * nullableMorphs() would have produced an unsignedBigInteger owner_id, which
 * cannot hold the former.
 */
it('lets a ULID-keyed owner and a bigint-keyed owner share the table', function () {
    $tenant = $this->makeTenant('Clinic A');
    $user = $this->makeUser('Solo Author');

    $this->actingForOwner($tenant);
    Post::query()->create(['type' => 'post']);

    $this->actingForOwner($user);
    Post::query()->create(['type' => 'post']);

    $this->actingForOwner($tenant);

    expect(Post::query()->count())->toBe(1)
        ->and(Post::query()->first()->owner_id)->toBe((string) $tenant->getKey())
        ->and(Post::query()->forOwner($user)->first()->owner_id)->toBe((string) $user->getKey())
        ->and(Post::query()->forOwner($user)->first()->owner)->toBeInstanceOf($user::class);
});

it('lets two owners hold the same slug', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);
    $first = Post::query()->create(['type' => 'post']);
    $first->setTranslation('en', ['title' => 'About Us']);

    $this->actingForOwner($b);
    $second = Post::query()->create(['type' => 'post']);
    $second->setTranslation('en', ['title' => 'About Us']);

    // No -2 suffix: the slugs live in different owner scopes.
    expect($first->slug('en'))->toBe('about-us')
        ->and($second->slug('en'))->toBe('about-us');
});

it('propagates an owner change down to translations', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);
    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('en', ['title' => 'Moving House']);

    $post->assignOwner($b);
    $post->save();

    expect($post->translations()->first()->owner_key)->toBe(OwnerKey::for($b));
});

it('respects an explicitly assigned owner over the resolved one', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);

    $post = new Post(['type' => 'post']);
    $post->assignOwner($b);
    $post->save();

    expect($post->owner_key)->toBe(OwnerKey::for($b));
});

it('keeps the scope working across a join', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    $this->actingForOwner($a);
    $mine = Post::query()->create(['type' => 'post']);
    $mine->setTranslation('en', ['title' => 'Mine']);

    $this->actingForOwner($b);
    $theirs = Post::query()->create(['type' => 'post']);
    $theirs->setTranslation('en', ['title' => 'Theirs']);

    $this->actingForOwner($a);

    // The scope qualifies its column with the table name, so joining a table
    // that also has an owner_key stays unambiguous.
    $posts = Post::query()
        ->join('blogify_post_translations', 'blogify_post_translations.post_id', '=', 'blogify_posts.id')
        ->select('blogify_posts.*')
        ->get();

    expect($posts)->toHaveCount(1)
        ->and($posts->first()->slug('en'))->toBe('mine');
});
