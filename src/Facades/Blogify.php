<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Facades;

use Closure;
use Enadstack\Blogify\Blogify as BlogifyManager;
use Enadstack\Blogify\Models\Post;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Model|null currentOwner()
 * @method static string currentOwnerKey()
 * @method static Builder postsFor(Model|null $owner)
 * @method static Builder platformPosts()
 * @method static Builder allPosts()
 * @method static Builder terms(string $taxonomy)
 * @method static Post|null findBySlug(string $slug, string|null $locale = null, Model|null $owner = null)
 * @method static Post|null resolveHistoricalSlug(string $slug, string|null $locale = null, Model|null $owner = null)
 * @method static array seo(Post $post, string|null $locale = null, callable|null $urlBuilder = null)
 * @method static array schema(Post $post, string|null $locale = null, callable|null $urlBuilder = null)
 * @method static Generator sitemap(Model|null $owner, callable $urlBuilder)
 * @method static string postModel()
 * @method static string termModel()
 *
 * @see BlogifyManager
 */
class Blogify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BlogifyManager::class;
    }

    /**
     * Register how the current owner should be resolved.
     *
     * Declared explicitly rather than proxied, because the underlying method is
     * static — the callback has to outlive any resolved instance.
     *
     * @param  Closure(): (Model|null)  $callback
     */
    public static function resolveOwnerUsing(Closure $callback): void
    {
        BlogifyManager::resolveOwnerUsing($callback);
    }

    /**
     * Forget the registered owner callback. Primarily for tests.
     */
    public static function forgetOwnerCallback(): void
    {
        BlogifyManager::forgetOwnerCallback();
    }
}
