<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Casts\UnicodeJson;
use Enadstack\Blogify\Concerns\BelongsToBlogOwner;
use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Enadstack\Blogify\Concerns\HasBlogTranslations;
use Enadstack\Blogify\Enums\ContentFormat;
use Enadstack\Blogify\Enums\PostStatus;
use Enadstack\Blogify\Events\PostDeleted;
use Enadstack\Blogify\Events\PostPublished;
use Enadstack\Blogify\Events\PostUnpublished;
use Enadstack\Blogify\Models\Scopes\OwnerScope;
use Enadstack\Blogify\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A piece of content, independent of language.
 *
 * Everything locale-specific — title, slug, body, meta — lives in
 * PostTranslation. This row holds what is true regardless of language: who owns
 * it, who wrote it, whether it is published, and its taxonomy and media links.
 *
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_key
 * @property string|null $author_type
 * @property string|null $author_id
 * @property string $type
 * @property PostStatus $status
 * @property ContentFormat $content_format
 * @property Carbon|null $published_at
 * @property bool $featured
 * @property bool $is_pinned
 * @property bool $comments_enabled
 * @property bool $noindex
 * @property string|null $canonical_url
 * @property string $schema_type
 * @property int|null $reading_time
 * @property int $views_count
 * @property int $sort_order
 * @property array<string, mixed>|null $extra
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Post extends Model
{
    use BelongsToBlogOwner;
    use HasBlogifyKey;
    use HasBlogTranslations;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // The denormalised owner_key on translations and slug history exists so
        // per-owner unique slug indexes are possible and so slug lookups need no
        // join. The cost is that an owner change has to be propagated.
        static::saved(function (Post $post): void {
            if ($post->wasChanged('owner_key')) {
                $post->translations()->update(['owner_key' => $post->owner_key]);
                $post->slugHistory()->update(['owner_key' => $post->owner_key]);
            }
        });

        // created and updated are handled separately rather than through one
        // saved hook. wasRecentlyCreated stays true for the whole lifetime of the
        // instance, so a saved hook that consulted it would re-announce
        // publication on every subsequent save of a freshly created post.
        static::created(function (Post $post): void {
            if ($post->status === PostStatus::Published) {
                event(new PostPublished($post));
            }
        });

        static::updated(function (Post $post): void {
            $post->fireVisibilityEvents();
        });

        static::deleted(function (Post $post): void {
            event(new PostDeleted($post, $post->isForceDeleting()));
        });
    }

    public function getTable(): string
    {
        return (string) config('blogify.tables.posts', 'blogify_posts');
    }

    public function getConnectionName(): ?string
    {
        return config('blogify.database.connection') ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'content_format' => ContentFormat::class,
            'published_at' => 'datetime',
            'featured' => 'boolean',
            'is_pinned' => 'boolean',
            'comments_enabled' => 'boolean',
            'noindex' => 'boolean',
            'reading_time' => 'integer',
            'views_count' => 'integer',
            'sort_order' => 'integer',
            'extra' => UnicodeJson::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * @return class-string<Model>
     */
    public function translationModel(): string
    {
        /** @var class-string<Model> $class */
        $class = config('blogify.models.post_translation', PostTranslation::class);

        return $class;
    }

    public function translationForeignKey(): string
    {
        return 'post_id';
    }

    /**
     * The byline. May be a user, a staff member, or a tenant's own profile.
     *
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function featuredMedia(): BelongsTo
    {
        /** @var class-string<Media> $model */
        $model = config('blogify.models.media', Media::class);

        return $this->belongsTo($model, 'featured_media_id');
    }

    /**
     * Every taxonomy term attached to this post, across all taxonomies.
     *
     * @return BelongsToMany<Term, $this>
     */
    public function terms(): BelongsToMany
    {
        /** @var class-string<Term> $model */
        $model = config('blogify.models.term', Term::class);

        // OwnerScope is dropped here deliberately. The pivot already limits
        // results to this record's own relations, so the scope can never
        // protect anything — it can only hide. Two ways it would: a platform
        // term shared with a tenant's post would vanish whenever a tenant is
        // bound, and every attached term would vanish whenever nothing is
        // bound, as in a command or queued job.
        return $this->belongsToMany($model, (string) config('blogify.tables.post_term', 'blogify_post_term'))
            ->withoutGlobalScope(OwnerScope::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        /** @var class-string<Media> $model */
        $model = config('blogify.models.media', Media::class);

        return $this->hasMany($model, 'attachable_id')
            ->where('attachable_type', $this->getMorphClass());
    }

    /**
     * @return HasMany<SlugHistory, $this>
     */
    public function slugHistory(): HasMany
    {
        /** @var class-string<SlugHistory> $model */
        $model = config('blogify.models.slug_history', SlugHistory::class);

        return $this->hasMany($model, 'post_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * Publicly visible content: published, and past its publish date.
     *
     * The published_at check matters because a row can sit at status
     * 'published' with a future date if it was promoted by hand rather than by
     * blogify:publish-scheduled.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published->value)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    /**
     * Posts awaiting their publish date.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Scheduled->value);
    }

    /**
     * Scheduled posts whose time has come.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Scheduled->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    /**
     * Restrict to posts that have a published translation in a locale.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeInLocale(Builder $query, ?string $locale = null): Builder
    {
        $locale = Locales::resolve($locale);

        return $query->whereHas('translations', function (Builder $q) use ($locale): void {
            $q->where('locale', $locale)->where('is_published', true);
        });
    }

    /**
     * Newest first, pinned posts ahead of everything else.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Whether this post is currently publicly visible.
     */
    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /**
     * The slug for a locale, or null if that locale has no translation.
     */
    public function slug(?string $locale = null): ?string
    {
        $slug = $this->t('slug', $locale);

        return is_string($slug) ? $slug : null;
    }

    /**
     * Announce a change in public visibility, on update.
     *
     * Driven by the status transition rather than by isPublished(), because a
     * post scheduled for next week is not a visibility change today — the
     * scheduler announces that one when the date arrives.
     */
    protected function fireVisibilityEvents(): void
    {
        if (! $this->wasChanged('status')) {
            return;
        }

        $was = $this->getOriginal('status');
        $was = $was instanceof PostStatus ? $was : PostStatus::tryFrom((string) $was);

        $isPublished = $this->status === PostStatus::Published;
        $wasPublished = $was === PostStatus::Published;

        if ($isPublished && ! $wasPublished) {
            event(new PostPublished($this));

            return;
        }

        if ($wasPublished && ! $isPublished) {
            event(new PostUnpublished($this));
        }
    }
}
