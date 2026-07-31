<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Enadstack\Blogify\Concerns\HasBlogSlug;
use Enadstack\Blogify\Support\ReadingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's version of a post.
 *
 * Holds every field that differs by language, most importantly the slug — which
 * is why translations are rows rather than JSON: a slug in a JSON column can be
 * neither uniquely indexed nor looked up through an index.
 *
 * @property string $locale
 * @property string $owner_key
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $body
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $og_title
 * @property string|null $og_description
 * @property bool $is_published
 */
class PostTranslation extends Model
{
    use HasBlogifyKey;
    use HasBlogSlug;

    protected $guarded = ['id'];

    /**
     * Inherits the owner key from the post, records retired slugs, and keeps the
     * post's reading-time estimate current.
     */
    protected static function booted(): void
    {
        static::saved(function (PostTranslation $translation): void {
            $translation->recordRetiredSlug();
            $translation->refreshPostReadingTime();
        });
    }

    public function getTable(): string
    {
        return (string) config('blogify.tables.post_translations', 'blogify_post_translations');
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
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        /** @var class-string<Post> $model */
        $model = config('blogify.models.post', Post::class);

        return $this->belongsTo($model, 'post_id');
    }

    public function slugSourceAttribute(): string
    {
        return 'title';
    }

    /**
     * A slug must be unique per owner and locale — two tenants may both publish
     * "about-us", and so may the same post's Arabic and English translations.
     *
     * @return array<int, string>
     */
    public function slugScopeColumns(): array
    {
        return ['owner_key', 'locale'];
    }

    /**
     * Copy the owner key down from the post before the slug is de-duplicated.
     *
     * Called by HasBlogSlug ahead of slug generation, because slug uniqueness is
     * scoped by owner_key — checking it against an unset value would let a
     * duplicate through and then trip the index on insert.
     */
    protected function prepareSlugScope(): void
    {
        if ($this->getAttribute('owner_key') !== null && ! $this->isDirty('post_id')) {
            return;
        }

        // withoutGlobalScopes(): the parent is being fetched by primary key, and
        // its ownership is the very thing being read. Leaving OwnerScope on would
        // return null whenever no owner is bound — which is every command, queued
        // job and scheduler run — and the inherited columns would stay unset.
        $post = $this->relationLoaded('post')
            ? $this->getRelation('post')
            : $this->post()->withoutGlobalScopes()->first();

        if ($post instanceof Post) {
            $this->setAttribute('owner_key', $post->owner_key);
        }
    }

    /**
     * Remember the previous slug so the old URL can redirect instead of 404.
     */
    protected function recordRetiredSlug(): void
    {
        if (! config('blogify.seo.track_slug_history', true)) {
            return;
        }

        $previous = $this->getOriginal('slug');

        if (! is_string($previous) || $previous === '' || $previous === $this->slug) {
            return;
        }

        /** @var class-string<SlugHistory> $model */
        $model = config('blogify.models.slug_history', SlugHistory::class);

        $model::query()->updateOrCreate(
            [
                'owner_key' => $this->owner_key,
                'locale' => $this->locale,
                'slug' => $previous,
            ],
            ['post_id' => $this->getAttribute('post_id')]
        );
    }

    /**
     * Recalculate the post's reading time from its longest translation.
     *
     * Reading time lives on the post rather than the translation because it is
     * displayed alongside the post in listings, where the active locale may not
     * be known. The longest body is the honest estimate for the effort involved.
     */
    protected function refreshPostReadingTime(): void
    {
        if (! $this->wasChanged('body') && ! $this->wasRecentlyCreated) {
            return;
        }

        $post = $this->post()->withoutGlobalScopes()->first();

        if (! $post instanceof Post) {
            return;
        }

        $minutes = $post->translations()
            ->get()
            ->map(static fn (Model $t): int => ReadingTime::minutes((string) $t->getAttribute('body')))
            ->max() ?? 0;

        if ($post->reading_time !== $minutes) {
            $post->forceFill(['reading_time' => $minutes])->saveQuietly();
        }
    }
}
