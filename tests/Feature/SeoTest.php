<?php

declare(strict_types=1);

use Enadstack\Blogify\Enums\PostStatus;
use Enadstack\Blogify\Facades\Blogify;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Seo\SchemaBuilder;
use Enadstack\Blogify\Seo\SeoBuilder;
use Enadstack\Blogify\Seo\SitemapBuilder;

/**
 * Stands in for the application's routing, which the package knows nothing about.
 */
function blogUrl(): callable
{
    return fn (string $locale, string $slug): string => "https://example.test/{$locale}/blog/{$slug}";
}

function publishedPost(array $attributes = []): Post
{
    return Post::query()->create(array_merge([
        'type' => 'post',
        'status' => PostStatus::Published->value,
        'published_at' => now()->subDay(),
    ], $attributes));
}

describe('SeoBuilder', function () {
    it('prefers meta fields over the title and excerpt', function () {
        $post = publishedPost();
        $post->setTranslation('en', [
            'title' => 'Ten Tips',
            'excerpt' => 'A short excerpt.',
            'meta_title' => 'Ten Tips For Better Sleep | Clinic',
            'meta_description' => 'The meta description wins.',
        ]);

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($seo['title'])->toBe('Ten Tips For Better Sleep | Clinic')
            ->and($seo['description'])->toBe('The meta description wins.');
    });

    it('falls back to the title and excerpt when no meta is set', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Ten Tips', 'excerpt' => 'A short excerpt.']);

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($seo['title'])->toBe('Ten Tips')
            ->and($seo['description'])->toBe('A short excerpt.');
    });

    it('reports direction for the locale', function () {
        $post = publishedPost();
        $post->setTranslations([
            'en' => ['title' => 'Sleep'],
            'ar' => ['title' => 'النوم'],
        ]);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'ar', blogUrl())['direction'])->toBe('rtl')
            ->and(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['direction'])->toBe('ltr');
    });

    /*
     * The payoff from storing translations as rows: each locale has its own
     * indexed slug, so hreflang alternates can be built from sibling rows.
     */
    it('builds hreflang alternates from the sibling translations', function () {
        $post = publishedPost();
        $post->setTranslations([
            'en' => ['title' => 'Hello World'],
            'ar' => ['title' => 'مرحبا بالعالم'],
        ]);

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($seo['alternates'])->toHaveKeys(['en', 'ar', 'x-default'])
            ->and($seo['alternates']['en'])->toBe('https://example.test/en/blog/hello-world')
            ->and($seo['alternates']['ar'])->toBe('https://example.test/ar/blog/مرحبا-بالعالم')
            ->and($seo['alternates']['x-default'])->toBe($seo['alternates']['en']);
    });

    it('leaves unpublished locales out of the alternates', function () {
        $post = publishedPost();
        $post->setTranslations([
            'en' => ['title' => 'Hello World', 'is_published' => true],
            'ar' => ['title' => 'مرحبا بالعالم', 'is_published' => false],
        ]);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['alternates'])
            ->toHaveKeys(['en', 'x-default'])
            ->and(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['alternates'])
            ->not->toHaveKey('ar');
    });

    /*
     * noindex still says follow, so link equity from the page is not thrown away
     * along with the indexing instruction.
     */
    it('emits noindex, follow for a hidden post', function () {
        $post = publishedPost(['noindex' => true]);
        $post->setTranslation('en', ['title' => 'Hidden']);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['robots'])
            ->toBe('noindex, follow');
    });

    it('emits noindex for a draft', function () {
        $post = Post::query()->create(['type' => 'post', 'status' => PostStatus::Draft->value]);
        $post->setTranslation('en', ['title' => 'Draft']);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['robots'])
            ->toBe('noindex, follow');
    });

    it('emits index, follow for a live post', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Live']);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['robots'])
            ->toBe('index, follow');
    });

    it('prefers an explicit canonical url', function () {
        $post = publishedPost(['canonical_url' => 'https://canonical.test/original']);
        $post->setTranslation('en', ['title' => 'Syndicated']);

        expect(app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['canonical'])
            ->toBe('https://canonical.test/original');
    });

    /*
     * Truncation is mb_-safe, or a cut could land mid-character and produce
     * mojibake in Arabic.
     */
    it('truncates a long description without breaking multibyte characters', function () {
        $post = publishedPost();
        $post->setTranslation('ar', [
            'title' => 'مقال طويل',
            'meta_description' => str_repeat('كلمة ', 100),
        ]);

        $description = app(SeoBuilder::class)->forPost($post->fresh(), 'ar', blogUrl())['description'];

        expect(mb_strlen($description))->toBeLessThanOrEqual(160)
            ->and($description)->toEndWith('…')
            ->and(mb_check_encoding($description, 'UTF-8'))->toBeTrue();
    });

    it('reads the author name for the byline', function () {
        $author = $this->makeUser('Dr Sara');

        $post = publishedPost();
        $post->author_type = $author->getMorphClass();
        $post->author_id = (string) $author->getKey();
        $post->save();
        $post->setTranslation('en', ['title' => 'Bylined']);

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($seo['open_graph']['article:author'])->toBe('Dr Sara');
    });

    /*
     * A missing locale falls back to a sibling rather than blanking the page —
     * content in the wrong language still beats an empty document.
     */
    it('falls back to a sibling translation rather than emptying the page', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'English only']);

        config()->set('blogify.locales.supported', ['en', 'ar', 'de']);
        config()->set('blogify.locales.fallback', 'de');

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'de', blogUrl());

        expect($seo['title'])->toBe('English only')
            ->and($seo['direction'])->toBe('ltr');
    });

    it('returns a safe empty result for a post with no translations at all', function () {
        $post = publishedPost();

        $seo = app(SeoBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($seo['title'])->toBeNull()
            ->and($seo['description'])->toBeNull()
            ->and($seo['canonical'])->toBeNull()
            ->and($seo['alternates'])->toBe([])
            ->and($seo['robots'])->toBe('noindex, follow');
    });
});

describe('SchemaBuilder', function () {
    it('builds a JSON-LD article document', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Ten Tips', 'excerpt' => 'A short excerpt.']);

        $schema = app(SchemaBuilder::class)->forPost($post->fresh(), 'en', blogUrl());

        expect($schema['@context'])->toBe('https://schema.org')
            ->and($schema['@type'])->toBe('Article')
            ->and($schema['headline'])->toBe('Ten Tips')
            ->and($schema['inLanguage'])->toBe('en')
            ->and($schema['url'])->toBe('https://example.test/en/blog/ten-tips')
            ->and($schema['mainEntityOfPage'])->toBe(['@type' => 'WebPage', '@id' => $schema['url']])
            ->and($schema['description'])->toBe('A short excerpt.');
    });

    /*
     * The @type comes from a column so an editor can mark a piece as news without
     * a code change.
     */
    it('honours the post\'s schema type', function () {
        $post = publishedPost(['schema_type' => 'NewsArticle']);
        $post->setTranslation('en', ['title' => 'Breaking']);

        expect(app(SchemaBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['@type'])
            ->toBe('NewsArticle');
    });

    it('names the owner as publisher', function () {
        $tenant = $this->makeTenant('Clinic A');

        $post = publishedPost();
        $post->assignOwner($tenant)->save();
        $post->setTranslation('en', ['title' => 'Owned']);

        expect(app(SchemaBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['publisher'])
            ->toBe(['@type' => 'Organization', 'name' => 'Clinic A']);
    });

    it('uses tags as keywords', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Tagged']);

        $tag = Term::query()->create(['taxonomy' => 'tag']);
        $tag->setTranslation('en', ['name' => 'Sleep']);

        $category = Term::query()->create(['taxonomy' => 'category']);
        $category->setTranslation('en', ['name' => 'Guides']);

        $post->terms()->attach([$tag->getKey(), $category->getKey()]);

        // Categories are navigation, not keywords — only tags belong here.
        expect(app(SchemaBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['keywords'])
            ->toBe('Sleep');
    });

    it('reports reading time as an ISO 8601 duration', function () {
        config()->set('blogify.content.words_per_minute', 10);

        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Long', 'body' => str_repeat('word ', 30)]);

        expect(app(SchemaBuilder::class)->forPost($post->fresh(), 'en', blogUrl())['timeRequired'])
            ->toBe('PT3M');
    });

    /*
     * Escaped \uXXXX Arabic is valid JSON but unreadable in page source and
     * bloats the payload.
     */
    it('emits unescaped Arabic in the JSON output', function () {
        $post = publishedPost();
        $post->setTranslation('ar', ['title' => 'مرحبا بالعالم']);

        $json = app(SchemaBuilder::class)->jsonFor($post->fresh(), 'ar', blogUrl());

        expect($json)->toContain('مرحبا بالعالم')
            ->and($json)->not->toContain('\\u0645')
            ->and(json_decode($json, true))->toBeArray();
    });
});

describe('slug history', function () {
    it('resolves a retired slug back to its post', function () {
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Original Title']);

        $translation = $post->fresh()->translations->first();
        $translation->title = 'Renamed Title';
        $translation->slug = null;
        $translation->save();

        $resolved = Blogify::resolveHistoricalSlug('original-title', 'en');

        expect($resolved)->not->toBeNull()
            ->and($resolved->is($post))->toBeTrue()
            ->and($resolved->slug('en'))->toBe('renamed-title');
    });

    it('returns null for a slug that never existed', function () {
        expect(Blogify::resolveHistoricalSlug('never-existed', 'en'))->toBeNull();
    });

    it('keeps one owner\'s history out of another\'s reach', function () {
        $a = $this->makeTenant('Clinic A');
        $b = $this->makeTenant('Clinic B');

        $this->actingForOwner($a);
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Original Title']);

        $translation = $post->fresh()->translations->first();
        $translation->title = 'Renamed Title';
        $translation->slug = null;
        $translation->save();

        expect(Blogify::resolveHistoricalSlug('original-title', 'en'))->not->toBeNull();

        $this->actingForOwner($b);

        expect(Blogify::resolveHistoricalSlug('original-title', 'en'))->toBeNull();
    });
});

describe('findBySlug', function () {
    it('finds a published post by its slug and locale', function () {
        $post = publishedPost();
        $post->setTranslations([
            'en' => ['title' => 'Hello World'],
            'ar' => ['title' => 'مرحبا بالعالم'],
        ]);

        expect(Blogify::findBySlug('hello-world', 'en')->is($post))->toBeTrue()
            ->and(Blogify::findBySlug('مرحبا-بالعالم', 'ar')->is($post))->toBeTrue()
            ->and(Blogify::findBySlug('hello-world', 'ar'))->toBeNull();
    });

    it('ignores drafts', function () {
        $post = Post::query()->create(['type' => 'post', 'status' => PostStatus::Draft->value]);
        $post->setTranslation('en', ['title' => 'Hello World']);

        expect(Blogify::findBySlug('hello-world', 'en'))->toBeNull();
    });

    it('will not cross owner boundaries', function () {
        $a = $this->makeTenant('Clinic A');
        $b = $this->makeTenant('Clinic B');

        $this->actingForOwner($a);
        $post = publishedPost();
        $post->setTranslation('en', ['title' => 'Private Post']);

        expect(Blogify::findBySlug('private-post', 'en')?->is($post))->toBeTrue();

        $this->actingForOwner($b);

        expect(Blogify::findBySlug('private-post', 'en'))->toBeNull();
    });
});

describe('SitemapBuilder', function () {
    it('yields one entry per published translation', function () {
        $post = publishedPost();
        $post->setTranslations([
            'en' => ['title' => 'Hello World'],
            'ar' => ['title' => 'مرحبا بالعالم'],
        ]);

        $entries = iterator_to_array(app(SitemapBuilder::class)->forOwner(null, blogUrl()));

        expect($entries)->toHaveCount(2)
            ->and(array_column($entries, 'locale'))->toEqualCanonicalizing(['en', 'ar'])
            ->and($entries[0]['alternates'])->toHaveKeys(['en', 'ar']);
    });

    it('excludes drafts and noindex posts', function () {
        $live = publishedPost();
        $live->setTranslation('en', ['title' => 'Live']);

        $hidden = publishedPost(['noindex' => true]);
        $hidden->setTranslation('en', ['title' => 'Hidden']);

        $draft = Post::query()->create(['type' => 'post', 'status' => PostStatus::Draft->value]);
        $draft->setTranslation('en', ['title' => 'Draft']);

        $entries = iterator_to_array(app(SitemapBuilder::class)->forOwner(null, blogUrl()));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['loc'])->toContain('live');
    });

    it('gives pinned posts the highest priority', function () {
        $pinned = publishedPost(['is_pinned' => true]);
        $pinned->setTranslation('en', ['title' => 'Pinned']);

        $featured = publishedPost(['featured' => true]);
        $featured->setTranslation('en', ['title' => 'Featured']);

        $normal = publishedPost();
        $normal->setTranslation('en', ['title' => 'Normal']);

        $entries = collect(iterator_to_array(app(SitemapBuilder::class)->forOwner(null, blogUrl())))
            ->keyBy(fn (array $e): string => $e['locale'].'|'.$e['loc']);

        expect($entries->firstWhere('loc', 'https://example.test/en/blog/pinned')['priority'])->toBe('1.0')
            ->and($entries->firstWhere('loc', 'https://example.test/en/blog/featured')['priority'])->toBe('0.8')
            ->and($entries->firstWhere('loc', 'https://example.test/en/blog/normal')['priority'])->toBe('0.5');
    });

    it('separates one owner\'s sitemap from another\'s', function () {
        $a = $this->makeTenant('Clinic A');
        $b = $this->makeTenant('Clinic B');

        $this->actingForOwner($a);
        $mine = publishedPost();
        $mine->setTranslation('en', ['title' => 'Mine']);

        $this->actingForOwner($b);
        $theirs = publishedPost();
        $theirs->setTranslation('en', ['title' => 'Theirs']);

        $builder = app(SitemapBuilder::class);

        expect(iterator_to_array($builder->forOwner($a, blogUrl())))->toHaveCount(1)
            ->and(iterator_to_array($builder->forOwner($b, blogUrl())))->toHaveCount(1)
            ->and(iterator_to_array($builder->forAllOwners(blogUrl())))->toHaveCount(2);
    });
});
