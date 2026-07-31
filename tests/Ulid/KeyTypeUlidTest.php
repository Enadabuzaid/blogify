<?php

declare(strict_types=1);

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Support\OwnerKey;

/*
 * The same behaviour, with Blogify's own tables keyed by ULID.
 *
 * Applications that key everything with ULIDs need Blogify's tables to match,
 * so the migrations read blogify.database.key_type. This suite is the proof that
 * both configurations work, and that the owner columns stay string-typed either
 * way — a ULID-keyed owner and a bigint-keyed owner still share one table.
 *
 * The base test case comes from tests/Pest.php, which binds UlidTestCase to this
 * whole directory.
 */

it('gives posts ULID keys', function () {
    $post = Post::query()->create(['type' => 'post']);

    expect($post->getKey())->toBeString()
        ->and(strlen((string) $post->getKey()))->toBe(26)
        ->and($post->getIncrementing())->toBeFalse()
        ->and($post->getKeyType())->toBe('string');
});

it('keeps foreign keys working across ULID tables', function () {
    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('en', ['title' => 'Hello World']);

    $translation = $post->fresh()->translations->first();

    expect($translation->getAttribute('post_id'))->toBe($post->getKey())
        ->and($translation->getKey())->toBeString()
        ->and($post->slug('en'))->toBe('hello-world');
});

it('still stores a bigint-keyed owner alongside a ULID-keyed one', function () {
    $tenant = $this->makeTenant('Clinic A');
    $user = $this->makeUser('Solo Author');

    $forTenant = Post::query()->create(['type' => 'post']);
    $forTenant->assignOwner($tenant)->save();

    $forUser = Post::query()->create(['type' => 'post']);
    $forUser->assignOwner($user)->save();

    expect($forTenant->owner_key)->toBe(OwnerKey::for($tenant))
        ->and($forUser->owner_key)->toBe(OwnerKey::for($user))
        ->and($forTenant->fresh()->owner)->toBeInstanceOf($tenant::class)
        ->and($forUser->fresh()->owner)->toBeInstanceOf($user::class);
});

it('supports the taxonomy pivot with ULID keys', function () {
    $post = Post::query()->create(['type' => 'post']);

    $term = Term::query()->create(['taxonomy' => 'category']);
    $term->setTranslation('en', ['name' => 'Guides']);

    $post->terms()->attach($term);

    expect($post->fresh()->terms)->toHaveCount(1)
        ->and($post->fresh()->terms->first()->name('en'))->toBe('Guides');
});
