<?php

declare(strict_types=1);

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\PostTranslation;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('writes a null owner and the platform sentinel', function () {
    $post = Post::query()->create(['type' => 'post']);

    expect($post->owner_type)->toBeNull()
        ->and($post->owner_id)->toBeNull()
        ->and($post->owner_key)->toBe(OwnerKey::PLATFORM)
        ->and($post->isPlatformContent())->toBeTrue();
});

it('does not scope queries', function () {
    Post::query()->create(['type' => 'post']);
    Post::query()->create(['type' => 'post']);

    expect(Post::query()->count())->toBe(2);
});

/*
 * The reason owner_key exists at all.
 *
 * MySQL and PostgreSQL treat NULL as distinct from every other NULL inside a
 * unique index, so a unique(['owner_type', 'owner_id', 'slug']) would happily
 * accept two platform posts sharing a slug — both rows being NULL/NULL. The
 * non-null sentinel closes that hole, and this test is what proves it: without
 * owner_key the second insert would succeed.
 */
it('rejects two platform posts sharing a slug in one locale', function () {
    $first = Post::query()->create(['type' => 'post']);
    $first->setTranslation('en', ['title' => 'Hello World', 'body' => 'One']);

    $second = Post::query()->create(['type' => 'post']);

    // Insert through the query builder, bypassing the model's slug
    // de-duplication. The claim under test is about the database index, not the
    // application code that usually keeps it from being reached.
    expect(fn () => DB::table((new PostTranslation)->getTable())->insert([
        'post_id' => $second->getKey(),
        'locale' => 'en',
        'owner_key' => OwnerKey::PLATFORM,
        'title' => 'Hello World',
        'slug' => 'hello-world',
        'is_published' => true,
    ]))->toThrow(QueryException::class);
});

it('allows the same slug in different locales', function () {
    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslation('en', ['title' => 'About', 'slug' => 'about']);
    $post->setTranslation('ar', ['title' => 'About', 'slug' => 'about']);

    expect($post->fresh()->translations)->toHaveCount(2);
});

it('de-duplicates a colliding slug rather than failing', function () {
    $first = Post::query()->create(['type' => 'post']);
    $first->setTranslation('en', ['title' => 'Hello World']);

    $second = Post::query()->create(['type' => 'post']);
    $second->setTranslation('en', ['title' => 'Hello World']);

    expect($first->slug('en'))->toBe('hello-world')
        ->and($second->slug('en'))->toBe('hello-world-2');
});
