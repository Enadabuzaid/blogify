<?php

declare(strict_types=1);

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Models\TermTranslation;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('discriminates taxonomies within one table', function () {
    $category = Term::query()->create(['taxonomy' => 'category']);
    $category->setTranslation('en', ['name' => 'Guides']);

    $tag = Term::query()->create(['taxonomy' => 'tag']);
    $tag->setTranslation('en', ['name' => 'Sleep']);

    expect(Term::query()->taxonomy('category')->count())->toBe(1)
        ->and(Term::query()->taxonomy('tag')->count())->toBe(1)
        ->and(Term::query()->count())->toBe(2);
});

/*
 * A new taxonomy is a config value, not a migration — which is the whole reason
 * for one terms table with a discriminator rather than separate categories and
 * tags tables.
 */
it('accepts a taxonomy it was never told about', function () {
    $term = Term::query()->create(['taxonomy' => 'practice-area']);
    $term->setTranslation('ar', ['name' => 'قانون الأسرة']);

    expect(Term::query()->taxonomy('practice-area')->count())->toBe(1)
        ->and($term->slug('ar'))->toBe('قانون-الأسرة');
});

it('lets the same slug exist in two taxonomies', function () {
    $category = Term::query()->create(['taxonomy' => 'category']);
    $category->setTranslation('en', ['name' => 'News']);

    $tag = Term::query()->create(['taxonomy' => 'tag']);
    $tag->setTranslation('en', ['name' => 'News']);

    // No -2 suffix: taxonomy is part of the uniqueness scope.
    expect($category->slug('en'))->toBe('news')
        ->and($tag->slug('en'))->toBe('news');
});

it('enforces term slug uniqueness at the database level', function () {
    $first = Term::query()->create(['taxonomy' => 'category']);
    $first->setTranslation('en', ['name' => 'Guides']);

    $second = Term::query()->create(['taxonomy' => 'category']);

    expect(fn () => DB::table((new TermTranslation)->getTable())->insert([
        'term_id' => $second->getKey(),
        'locale' => 'en',
        'owner_key' => OwnerKey::PLATFORM,
        'taxonomy' => 'category',
        'name' => 'Guides',
        'slug' => 'guides',
    ]))->toThrow(QueryException::class);
});

it('supports a term hierarchy', function () {
    $parent = Term::query()->create(['taxonomy' => 'category']);
    $parent->setTranslation('en', ['name' => 'Health']);

    $child = Term::query()->create(['taxonomy' => 'category', 'parent_id' => $parent->getKey()]);
    $child->setTranslation('en', ['name' => 'Sleep']);

    expect($parent->children)->toHaveCount(1)
        ->and($child->parent->is($parent))->toBeTrue()
        ->and(Term::query()->roots()->count())->toBe(1);
});

it('attaches terms to posts', function () {
    $post = Post::query()->create(['type' => 'post']);

    $category = Term::query()->create(['taxonomy' => 'category']);
    $category->setTranslation('en', ['name' => 'Guides']);

    $tag = Term::query()->create(['taxonomy' => 'tag']);
    $tag->setTranslation('en', ['name' => 'Sleep']);

    $post->terms()->attach([$category->getKey(), $tag->getKey()]);

    expect($post->fresh()->terms)->toHaveCount(2)
        ->and($category->fresh()->posts)->toHaveCount(1);
});

/*
 * The platform defines shared terms while each tenant adds their own — the
 * reason terms carry owner columns at all.
 */
it('separates platform terms from tenant terms', function () {
    $tenant = $this->makeTenant('Clinic A');

    $this->actingForOwner(null);
    $shared = Term::query()->create(['taxonomy' => 'category']);
    $shared->setTranslation('en', ['name' => 'Announcements']);

    $this->actingForOwner($tenant);
    $own = Term::query()->create(['taxonomy' => 'category']);
    $own->setTranslation('en', ['name' => 'Clinic News']);

    expect(Term::query()->count())->toBe(1)
        ->and(Term::query()->first()->name('en'))->toBe('Clinic News')
        ->and(Term::query()->platform()->count())->toBe(1)
        ->and(Term::query()->allOwners()->count())->toBe(2);
});

it('lets a tenant reuse a platform term slug', function () {
    $tenant = $this->makeTenant('Clinic A');

    $this->actingForOwner(null);
    $shared = Term::query()->create(['taxonomy' => 'category']);
    $shared->setTranslation('en', ['name' => 'News']);

    $this->actingForOwner($tenant);
    $own = Term::query()->create(['taxonomy' => 'category']);
    $own->setTranslation('en', ['name' => 'News']);

    expect($shared->slug('en'))->toBe('news')
        ->and($own->slug('en'))->toBe('news');
});

it('propagates a taxonomy change down to translations', function () {
    $term = Term::query()->create(['taxonomy' => 'category']);
    $term->setTranslation('en', ['name' => 'Reclassified']);

    $term->taxonomy = 'tag';
    $term->save();

    expect($term->translations()->first()->taxonomy)->toBe('tag');
});

it('cascades translation deletion with the term', function () {
    $term = Term::query()->create(['taxonomy' => 'category']);
    $term->setTranslation('en', ['name' => 'Temporary']);

    expect(TermTranslation::query()->count())->toBe(1);

    $term->forceDelete();

    expect(TermTranslation::query()->count())->toBe(0);
});
