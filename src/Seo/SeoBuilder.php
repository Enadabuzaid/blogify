<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Seo;

use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\Locales;
use Illuminate\Database\Eloquent\Model;

/**
 * Assembles the SEO metadata for a post in one locale.
 *
 * Returns a plain array. The package ships no views, so rendering these into
 * tags is the application's job — which also means the same data can feed a
 * Blade layout, an Inertia page prop, or a JSON API without change.
 *
 * The hreflang alternates are the payoff from storing translations as rows: each
 * locale has its own indexed slug, so the alternate URLs can be built from
 * sibling rows rather than parsed out of a JSON column.
 */
class SeoBuilder
{
    /**
     * Metadata for a post in one locale.
     *
     * $urlBuilder turns a locale and slug into an absolute URL. It is a callback
     * because only the application knows its own routing — subdomain per tenant,
     * path prefix per locale, or something else entirely.
     *
     * @param  (callable(string $locale, string $slug): string)|null  $urlBuilder
     * @return array<string, mixed>
     */
    public function forPost(Post $post, ?string $locale = null, ?callable $urlBuilder = null): array
    {
        $locale = Locales::resolve($locale);
        $translation = $post->translation($locale);

        if ($translation === null) {
            return $this->empty($locale);
        }

        $title = (string) $translation->getAttribute('title');
        $slug = (string) $translation->getAttribute('slug');

        $url = $urlBuilder !== null ? $urlBuilder($locale, $slug) : null;
        $canonical = $post->canonical_url ?: $url;

        $description = $this->description($translation);

        return [
            'locale' => $locale,
            'direction' => Locales::direction($locale),
            'title' => $this->firstFilled($translation->getAttribute('meta_title'), $title),
            'description' => $description,
            'canonical' => $canonical,

            // A noindex post still gets follow, so link equity from it is not
            // discarded along with the indexing instruction.
            'robots' => $post->noindex || ! $post->isPublished() ? 'noindex, follow' : 'index, follow',

            'open_graph' => [
                'og:type' => 'article',
                'og:locale' => str_replace('-', '_', $locale),
                'og:title' => $this->firstFilled($translation->getAttribute('og_title'), $translation->getAttribute('meta_title'), $title),
                'og:description' => $this->firstFilled($translation->getAttribute('og_description'), $description),
                'og:url' => $canonical,
                'og:site_name' => config('blogify.seo.site_name'),
                'og:image' => $post->featuredMedia?->url(),
                'article:published_time' => $post->published_at?->toIso8601String(),
                'article:modified_time' => $post->updated_at?->toIso8601String(),
                'article:author' => $this->authorName($post),
            ],

            'twitter' => [
                'twitter:card' => $post->featuredMedia !== null ? 'summary_large_image' : 'summary',
                'twitter:title' => $this->firstFilled($translation->getAttribute('meta_title'), $title),
                'twitter:description' => $description,
                'twitter:image' => $post->featuredMedia?->url(),
            ],

            'alternates' => $this->alternates($post, $locale, $urlBuilder),
        ];
    }

    /**
     * hreflang alternates for every other published locale of this post.
     *
     * x-default points at the configured default locale when the post has one,
     * which is what tells a search engine which version to serve a visitor whose
     * language matches none of them.
     *
     * @param  (callable(string $locale, string $slug): string)|null  $urlBuilder
     * @return array<string, string>
     */
    public function alternates(Post $post, ?string $current = null, ?callable $urlBuilder = null): array
    {
        if ($urlBuilder === null) {
            return [];
        }

        $alternates = [];
        $default = Locales::default();

        foreach ($post->translations as $translation) {
            if (! $translation->getAttribute('is_published')) {
                continue;
            }

            $locale = (string) $translation->getAttribute('locale');
            $slug = (string) $translation->getAttribute('slug');

            if ($slug === '') {
                continue;
            }

            $alternates[$locale] = $urlBuilder($locale, $slug);

            if ($locale === $default) {
                $alternates['x-default'] = $alternates[$locale];
            }
        }

        return $alternates;
    }

    /**
     * The description, preferring an explicit meta_description over the excerpt.
     *
     * Truncated to 160 characters, the point past which search engines stop
     * displaying it. mb_* throughout, or a cut could land mid-character and
     * produce mojibake in Arabic.
     */
    protected function description(Model $translation): ?string
    {
        $value = $this->firstFilled(
            $translation->getAttribute('meta_description'),
            $translation->getAttribute('excerpt')
        );

        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return mb_strlen($value) > 160
            ? rtrim(mb_substr($value, 0, 157)).'…'
            : $value;
    }

    /**
     * A display name for the post's author, if one can be read.
     *
     * Author models differ per application, so several conventional attributes
     * are tried and null is returned rather than guessed at.
     */
    protected function authorName(Post $post): ?string
    {
        $author = $post->author;

        if (! $author instanceof Model) {
            return null;
        }

        foreach (['name', 'display_name', 'full_name', 'title'] as $attribute) {
            $value = $author->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            // Translatable name columns are commonly JSON maps keyed by locale.
            if (is_array($value)) {
                foreach (Locales::fallbackChain() as $locale) {
                    if (isset($value[$locale]) && is_string($value[$locale])) {
                        return $value[$locale];
                    }
                }
            }
        }

        return null;
    }

    /**
     * The first argument that is a non-empty string.
     */
    protected function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function empty(string $locale): array
    {
        return [
            'locale' => $locale,
            'direction' => Locales::direction($locale),
            'title' => null,
            'description' => null,
            'canonical' => null,
            'robots' => 'noindex, follow',
            'open_graph' => [],
            'twitter' => [],
            'alternates' => [],
        ];
    }
}
