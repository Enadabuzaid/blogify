<?php

declare(strict_types=1);

namespace Enadstack\Blogify;

use Closure;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\SlugHistory;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Seo\SchemaBuilder;
use Enadstack\Blogify\Seo\SeoBuilder;
use Enadstack\Blogify\Seo\SitemapBuilder;
use Enadstack\Blogify\Support\Locales;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's public entry point, reached through the Blogify facade.
 *
 * A thin facade over the models and resolvers — it holds no content logic of
 * its own beyond assembling queries.
 */
class Blogify
{
    /**
     * Callback used by CallbackOwnerResolver.
     *
     * Static rather than config so applications can register a closure without
     * breaking `php artisan config:cache`.
     *
     * @var (Closure(): (Model|null))|null
     */
    protected static ?Closure $ownerCallback = null;

    /**
     * Register how the current owner should be resolved.
     *
     * Only consulted when blogify.tenancy.resolver is CallbackOwnerResolver.
     *
     * @param  Closure(): (Model|null)  $callback
     */
    public static function resolveOwnerUsing(Closure $callback): void
    {
        static::$ownerCallback = $callback;
    }

    /**
     * @return (Closure(): (Model|null))|null
     */
    public static function ownerCallback(): ?Closure
    {
        return static::$ownerCallback;
    }

    /**
     * Forget the registered callback. Primarily for tests.
     */
    public static function forgetOwnerCallback(): void
    {
        static::$ownerCallback = null;
    }

    /**
     * The owner for the current request, or null for platform-level content.
     */
    public function currentOwner(): ?Model
    {
        return app(OwnerResolver::class)->resolve();
    }

    /**
     * The owner key for the current request.
     */
    public function currentOwnerKey(): string
    {
        return OwnerKey::for($this->currentOwner());
    }

    /**
     * A post query for one owner, bypassing whichever owner is resolved.
     *
     * @return Builder<Post>
     */
    public function postsFor(?Model $owner): Builder
    {
        return $this->postModel()::query()->forOwner($owner);
    }

    /**
     * A post query for platform-level content.
     *
     * @return Builder<Post>
     */
    public function platformPosts(): Builder
    {
        return $this->postModel()::query()->platform();
    }

    /**
     * A post query across every owner — for admin and moderation views.
     *
     * @return Builder<Post>
     */
    public function allPosts(): Builder
    {
        return $this->postModel()::query()->allOwners();
    }

    /**
     * A term query for one taxonomy, scoped to the current owner.
     *
     * @return Builder<Term>
     */
    public function terms(string $taxonomy): Builder
    {
        return $this->termModel()::query()->where('taxonomy', $taxonomy);
    }

    /**
     * Find a published post by slug, scoped to the current owner and locale.
     *
     * A single-table indexed lookup — no join — because owner_key is
     * denormalised onto the translations table.
     */
    public function findBySlug(string $slug, ?string $locale = null, ?Model $owner = null): ?Post
    {
        $locale = Locales::resolve($locale);
        $ownerKey = $owner !== null ? OwnerKey::for($owner) : $this->currentOwnerKey();

        /** @var class-string<Post> $model */
        $model = $this->postModel();

        return $model::query()
            ->allOwners()
            ->published()
            ->whereHas('translations', function ($query) use ($slug, $locale, $ownerKey): void {
                $query->where('owner_key', $ownerKey)
                    ->where('locale', $locale)
                    ->where('slug', $slug)
                    ->where('is_published', true);
            })
            ->with('translations')
            ->first();
    }

    /**
     * Find the post that a retired slug used to belong to.
     *
     * Renaming a slug otherwise breaks every inbound link and search result
     * pointing at the old URL. Resolving the old slug lets the application answer
     * with a 301 to the current one, preserving the ranking it had earned:
     *
     *     $post = Blogify::resolveHistoricalSlug($slug, $locale);
     *     if ($post) {
     *         return redirect()->route('blog.show', $post->slug($locale), 301);
     *     }
     */
    public function resolveHistoricalSlug(string $slug, ?string $locale = null, ?Model $owner = null): ?Post
    {
        $locale = Locales::resolve($locale);
        $ownerKey = $owner !== null ? OwnerKey::for($owner) : $this->currentOwnerKey();

        /** @var class-string<SlugHistory> $historyModel */
        $historyModel = config('blogify.models.slug_history', SlugHistory::class);

        $history = $historyModel::query()
            ->where('owner_key', $ownerKey)
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->first();

        if ($history === null) {
            return null;
        }

        /** @var class-string<Post> $model */
        $model = $this->postModel();

        return $model::query()
            ->allOwners()
            ->with('translations')
            ->whereKey($history->getAttribute('post_id'))
            ->first();
    }

    /**
     * SEO metadata for a post in one locale.
     *
     * @param  (callable(string $locale, string $slug): string)|null  $urlBuilder
     * @return array<string, mixed>
     */
    public function seo(Post $post, ?string $locale = null, ?callable $urlBuilder = null): array
    {
        return app(SeoBuilder::class)->forPost($post, $locale, $urlBuilder);
    }

    /**
     * JSON-LD structured data for a post in one locale.
     *
     * @param  (callable(string $locale, string $slug): string)|null  $urlBuilder
     * @return array<string, mixed>
     */
    public function schema(Post $post, ?string $locale = null, ?callable $urlBuilder = null): array
    {
        return app(SchemaBuilder::class)->forPost($post, $locale, $urlBuilder);
    }

    /**
     * Sitemap entries for one owner, or for platform content when given null.
     *
     * @param  callable(string $locale, string $slug): string  $urlBuilder
     * @return \Generator<int, array<string, mixed>>
     */
    public function sitemap(?Model $owner, callable $urlBuilder): \Generator
    {
        return app(SitemapBuilder::class)->forOwner($owner, $urlBuilder);
    }

    /**
     * The configured post model class.
     *
     * @return class-string<Post>
     */
    public function postModel(): string
    {
        /** @var class-string<Post> $class */
        $class = config('blogify.models.post', Post::class);

        return $class;
    }

    /**
     * The configured term model class.
     *
     * @return class-string<Term>
     */
    public function termModel(): string
    {
        /** @var class-string<Term> $class */
        $class = config('blogify.models.term', Term::class);

        return $class;
    }
}
