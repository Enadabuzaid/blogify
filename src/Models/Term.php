<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Casts\UnicodeJson;
use Enadstack\Blogify\Concerns\BelongsToBlogOwner;
use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Enadstack\Blogify\Concerns\HasBlogTranslations;
use Enadstack\Blogify\Events\TermCreated;
use Enadstack\Blogify\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A taxonomy term — a category, a tag, or whatever else the application needs.
 *
 * One table serves every taxonomy, discriminated by the `taxonomy` column. That
 * keeps a new taxonomy a config change rather than a migration, and it lets the
 * platform define shared terms (null owner) while each tenant adds their own.
 *
 * Hierarchy is available through parent_id, which most applications will only
 * use for categories.
 *
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_key
 * @property string $taxonomy
 * @property int $sort_order
 * @property bool $is_visible
 * @property array<string, mixed>|null $extra
 */
class Term extends Model
{
    use BelongsToBlogOwner;
    use HasBlogifyKey;
    use HasBlogTranslations;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Propagates the denormalised owner key and taxonomy down to translations,
     * which need both for their unique slug index.
     */
    protected static function booted(): void
    {
        static::saved(function (Term $term): void {
            if ($term->wasChanged('owner_key') || $term->wasChanged('taxonomy')) {
                $term->translations()->update([
                    'owner_key' => $term->owner_key,
                    'taxonomy' => $term->taxonomy,
                ]);
            }
        });

        static::created(function (Term $term): void {
            event(new TermCreated($term));
        });
    }

    public function getTable(): string
    {
        return (string) config('blogify.tables.terms', 'blogify_terms');
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
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
            'extra' => UnicodeJson::class,
        ];
    }

    /**
     * @return class-string<Model>
     */
    public function translationModel(): string
    {
        /** @var class-string<Model> $class */
        $class = config('blogify.models.term_translation', TermTranslation::class);

        return $class;
    }

    public function translationForeignKey(): string
    {
        return 'term_id';
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        /** @var class-string<Post> $model */
        $model = config('blogify.models.post', Post::class);

        // OwnerScope is dropped here deliberately. The pivot already limits
        // results to this record's own relations, so the scope can never
        // protect anything — it can only hide. Two ways it would: a platform
        // post shared with a tenant's term would vanish whenever a tenant is
        // bound, and every attached term would vanish whenever nothing is
        // bound, as in a command or queued job.
        return $this->belongsToMany($model, (string) config('blogify.tables.post_term', 'blogify_post_term'))
            ->withoutGlobalScope(OwnerScope::class)
            ->withPivot('sort_order');
    }

    /**
     * @param  Builder<Term>  $query
     * @return Builder<Term>
     */
    public function scopeTaxonomy(Builder $query, string $taxonomy): Builder
    {
        return $query->where('taxonomy', $taxonomy);
    }

    /**
     * @param  Builder<Term>  $query
     * @return Builder<Term>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * Only top-level terms.
     *
     * @param  Builder<Term>  $query
     * @return Builder<Term>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * The term's name in a locale.
     */
    public function name(?string $locale = null): ?string
    {
        $name = $this->t('name', $locale);

        return is_string($name) ? $name : null;
    }

    /**
     * The term's slug in a locale.
     */
    public function slug(?string $locale = null): ?string
    {
        $slug = $this->t('slug', $locale);

        return is_string($slug) ? $slug : null;
    }
}
