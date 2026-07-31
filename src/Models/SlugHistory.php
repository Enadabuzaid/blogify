<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug a post used to have.
 *
 * Renaming a slug breaks every inbound link and every search result pointing at
 * the old URL. Recording the retired slug lets the application answer with a 301
 * instead of a 404, which preserves the ranking the old URL had earned.
 *
 * Rows are written automatically by PostTranslation when a slug changes, unless
 * blogify.seo.track_slug_history is disabled.
 *
 * @property string $locale
 * @property string $owner_key
 * @property string $slug
 */
class SlugHistory extends Model
{
    use HasBlogifyKey;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('blogify.tables.slug_history', 'blogify_slug_history');
    }

    public function getConnectionName(): ?string
    {
        return config('blogify.database.connection') ?? parent::getConnectionName();
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
}
