<?php

declare(strict_types=1);

use Enadstack\Blogify\Models\Post;

it('stores one row per locale', function () {
    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslations([
        'en' => ['title' => 'Ten Tips for Better Sleep', 'body' => 'English body'],
        'ar' => ['title' => 'عشر نصائح لنوم أفضل', 'body' => 'النص العربي'],
    ]);

    expect($post->fresh()->translations)->toHaveCount(2)
        ->and($post->translatedLocales())->toEqualCanonicalizing(['en', 'ar']);
});

/*
 * The reason Blogify ships its own slugger.
 *
 * Str::slug forces ASCII, which mangles Arabic into unreadable transliteration
 * ('مرحبا بالعالم' becomes 'mrhba-balaaalm') and drops Hebrew and CJK entirely.
 * Neither is acceptable when RTL support is the premise of the package.
 */
it('keeps Arabic in the slug rather than transliterating it', function () {
    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('ar', ['title' => 'مرحبا بالعالم']);

    expect(Str::slug('مرحبا بالعالم'))->toBe('mrhba-balaaalm')
        ->and($post->slug('ar'))->toBe('مرحبا-بالعالم');
});

it('gives each locale its own slug', function () {
    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslations([
        'en' => ['title' => 'Hello World'],
        'ar' => ['title' => 'مرحبا بالعالم'],
    ]);

    expect($post->slug('en'))->toBe('hello-world')
        ->and($post->slug('ar'))->toBe('مرحبا-بالعالم');
});

it('reads a translated attribute for a locale', function () {
    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslations([
        'en' => ['title' => 'Sleep Tips'],
        'ar' => ['title' => 'نصائح النوم'],
    ]);

    expect($post->t('title', 'en'))->toBe('Sleep Tips')
        ->and($post->t('title', 'ar'))->toBe('نصائح النوم');
});

it('falls back when a locale has no translation', function () {
    config()->set('blogify.locales.fallback', 'en');

    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('en', ['title' => 'Only English']);

    expect($post->t('title', 'ar'))->toBe('Only English')
        ->and($post->hasTranslation('ar'))->toBeFalse()
        ->and($post->hasTranslation('en'))->toBeTrue();
});

/*
 * Publishing one locale ahead of another is a real editorial need — the Arabic
 * copy is often ready long before the English one. That is why is_published
 * lives on the translation rather than only on the post.
 */
it('publishes one locale without the other', function () {
    $post = Post::query()->create(['type' => 'post', 'status' => 'published']);

    $post->setTranslations([
        'ar' => ['title' => 'جاهز', 'is_published' => true],
        'en' => ['title' => 'Not ready yet', 'is_published' => false],
    ]);

    expect(Post::query()->inLocale('ar')->count())->toBe(1)
        ->and(Post::query()->inLocale('en')->count())->toBe(0);
});

it('updates a translation in place rather than duplicating it', function () {
    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslation('en', ['title' => 'First Title']);
    $post->setTranslation('en', ['title' => 'Second Title']);

    expect($post->fresh()->translations)->toHaveCount(1)
        ->and($post->t('title', 'en'))->toBe('Second Title');
});

it('records a retired slug so the old URL can redirect', function () {
    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('en', ['title' => 'Original Title']);

    expect($post->slug('en'))->toBe('original-title');

    $translation = $post->fresh()->translations->first();
    $translation->title = 'Renamed Title';
    $translation->slug = null;
    $translation->save();

    expect($post->fresh()->slug('en'))->toBe('renamed-title')
        ->and($post->slugHistory()->pluck('slug')->all())->toBe(['original-title']);
});

it('leaves no slug history when tracking is disabled', function () {
    config()->set('blogify.seo.track_slug_history', false);

    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('en', ['title' => 'Original Title']);

    $translation = $post->fresh()->translations->first();
    $translation->title = 'Renamed Title';
    $translation->slug = null;
    $translation->save();

    expect($post->slugHistory()->count())->toBe(0);
});

it('rejects an unsupported locale by narrowing it to the fallback', function () {
    config()->set('blogify.locales.supported', ['en', 'ar']);
    config()->set('blogify.locales.fallback', 'en');

    $post = Post::query()->create(['type' => 'post']);
    $post->setTranslation('de', ['title' => 'Guten Tag']);

    expect($post->fresh()->translations->first()->locale)->toBe('en');
});

it('derives reading time from the longest translation', function () {
    config()->set('blogify.content.words_per_minute', 10);

    $post = Post::query()->create(['type' => 'post']);

    $post->setTranslation('en', ['title' => 'Short', 'body' => str_repeat('word ', 10)]);
    expect($post->fresh()->reading_time)->toBe(1);

    $post->setTranslation('ar', ['title' => 'طويل', 'body' => str_repeat('كلمة ', 50)]);
    expect($post->fresh()->reading_time)->toBe(5);
});
