<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Seo;

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\Locales;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Produces sitemap entries for published content.
 *
 * Entries stream through a Generator and posts are read with chunkById, so a
 * tenant with a hundred thousand posts does not have to fit in memory. The
 * package emits data, not XML — the application decides whether that becomes a
 * file, a cached response, or a submission to a search console.
 */
class SitemapBuilder
{
    /**
     * Sitemap entries for one owner, or for platform content when given null.
     *
     * Each published translation is its own entry with its own URL, and carries
     * the other locales as alternates — the xhtml:link rel="alternate" pairs
     * that tell a crawler the pages are translations rather than duplicates.
     *
     * @param  callable(string $locale, string $slug): string  $urlBuilder
     * @return Generator<int, array<string, mixed>>
     */
    public function forOwner(?Model $owner, callable $urlBuilder): Generator
    {
        /** @var class-string<Post> $model */
        $model = config('blogify.models.post', Post::class);

        $chunk = max(1, (int) config('blogify.seo.sitemap.chunk', 1000));

        $query = $model::query()
            ->forOwner($owner)
            ->published()
            ->where('noindex', false)
            ->with('translations');

        foreach ($this->chunks($query, $chunk) as $post) {
            foreach ($this->entriesFor($post, $urlBuilder) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * Sitemap entries across every owner — for a platform-wide index.
     *
     * @param  callable(string $locale, string $slug): string  $urlBuilder
     * @return Generator<int, array<string, mixed>>
     */
    public function forAllOwners(callable $urlBuilder): Generator
    {
        /** @var class-string<Post> $model */
        $model = config('blogify.models.post', Post::class);

        $chunk = max(1, (int) config('blogify.seo.sitemap.chunk', 1000));

        $query = $model::query()
            ->allOwners()
            ->published()
            ->where('noindex', false)
            ->with('translations');

        foreach ($this->chunks($query, $chunk) as $post) {
            foreach ($this->entriesFor($post, $urlBuilder) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * One entry per published translation of a post.
     *
     * @param  callable(string $locale, string $slug): string  $urlBuilder
     * @return array<int, array<string, mixed>>
     */
    protected function entriesFor(Post $post, callable $urlBuilder): array
    {
        $published = $post->translations->filter(
            static fn (Model $t): bool => (bool) $t->getAttribute('is_published')
                && (string) $t->getAttribute('slug') !== ''
        );

        $alternates = $published
            ->mapWithKeys(static fn (Model $t): array => [
                (string) $t->getAttribute('locale') => $urlBuilder(
                    (string) $t->getAttribute('locale'),
                    (string) $t->getAttribute('slug')
                ),
            ])
            ->all();

        return $published->map(function (Model $translation) use ($post, $urlBuilder, $alternates): array {
            $locale = (string) $translation->getAttribute('locale');

            return [
                'loc' => $urlBuilder($locale, (string) $translation->getAttribute('slug')),
                'lastmod' => ($post->updated_at ?? $post->published_at)?->toIso8601String(),
                'changefreq' => 'weekly',

                // Pinned and featured posts are the ones the owner considers
                // most important, and priority is the only place a sitemap can
                // say so.
                'priority' => $post->is_pinned ? '1.0' : ($post->featured ? '0.8' : '0.5'),

                'locale' => $locale,
                'direction' => Locales::direction($locale),
                'alternates' => $alternates,
            ];
        })->values()->all();
    }

    /**
     * Walk a query in chunks without holding the whole result set.
     *
     * @param  Builder<Post>  $query
     * @return Generator<int, Post>
     */
    protected function chunks($query, int $chunk): Generator
    {
        foreach ($query->lazyById($chunk) as $post) {
            /** @var Post $post */
            yield $post;
        }
    }
}
