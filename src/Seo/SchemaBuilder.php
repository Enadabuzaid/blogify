<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Seo;

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\Locales;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds a JSON-LD structured-data document for a post.
 *
 * Returns an array so the application can inline it, cache it, or merge it into
 * a larger graph. The @type comes from the post's schema_type column, which lets
 * an editor mark a piece as NewsArticle or BlogPosting without a code change.
 */
class SchemaBuilder
{
    /**
     * @param  (callable(string $locale, string $slug): string)|null  $urlBuilder
     * @return array<string, mixed>
     */
    public function forPost(Post $post, ?string $locale = null, ?callable $urlBuilder = null): array
    {
        $locale = Locales::resolve($locale);
        $translation = $post->translation($locale);

        if ($translation === null) {
            return [];
        }

        $slug = (string) $translation->getAttribute('slug');
        $url = $post->canonical_url ?: ($urlBuilder !== null ? $urlBuilder($locale, $slug) : null);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $post->schema_type ?: (string) config('blogify.seo.default_schema_type', 'Article'),
            'headline' => (string) $translation->getAttribute('title'),
            'inLanguage' => $locale,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
        ];

        if ($url !== null) {
            $schema['url'] = $url;

            // mainEntityOfPage is what tells a crawler this document describes
            // the page itself rather than something merely mentioned on it.
            $schema['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $url];
        }

        $description = $translation->getAttribute('meta_description') ?: $translation->getAttribute('excerpt');

        if (is_string($description) && $description !== '') {
            $schema['description'] = trim(strip_tags($description));
        }

        if ($post->featuredMedia !== null) {
            $schema['image'] = $post->featuredMedia->url();
        }

        if ($post->reading_time !== null) {
            // ISO 8601 duration.
            $schema['timeRequired'] = 'PT'.$post->reading_time.'M';
        }

        $author = $this->entityName($post->author);

        if ($author !== null) {
            $schema['author'] = ['@type' => 'Person', 'name' => $author];
        }

        // The publisher is the blog's owner — the tenant whose site this is, or
        // the platform itself for platform-level content.
        $publisher = $this->entityName($post->owner) ?? config('blogify.seo.site_name');

        if (is_string($publisher) && $publisher !== '') {
            $schema['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }

        $keywords = $this->keywords($post, $locale);

        if ($keywords !== []) {
            $schema['keywords'] = implode(', ', $keywords);
        }

        return $schema;
    }

    /**
     * The document as a JSON string ready to inline in a script tag.
     */
    public function jsonFor(Post $post, ?string $locale = null, ?callable $urlBuilder = null): string
    {
        $schema = $this->forPost($post, $locale, $urlBuilder);

        // JSON_UNESCAPED_UNICODE so Arabic stays readable rather than becoming
        // \uXXXX escapes, and UNESCAPED_SLASHES to keep URLs legible.
        return (string) json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Tag names for a locale, used as schema keywords.
     *
     * @return array<int, string>
     */
    protected function keywords(Post $post, string $locale): array
    {
        return $post->terms
            ->filter(static fn (Model $term): bool => $term->getAttribute('taxonomy') === 'tag')
            ->map(static fn (Model $term): ?string => $term->name($locale))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A display name for a related model, handling translatable JSON columns.
     *
     * Owner and author models belong to the application, not the package, so
     * several conventional attributes are tried and null returned on failure
     * rather than assuming a schema.
     */
    protected function entityName(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach (['name', 'display_name', 'full_name', 'title'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                foreach (Locales::fallbackChain() as $locale) {
                    if (isset($value[$locale]) && is_string($value[$locale]) && $value[$locale] !== '') {
                        return $value[$locale];
                    }
                }
            }
        }

        return null;
    }
}
