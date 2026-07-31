<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Enadstack\Blogify\Concerns\HasBlogSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's version of a taxonomy term.
 *
 * @property string $locale
 * @property string $owner_key
 * @property string $taxonomy
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
class TermTranslation extends Model
{
    use HasBlogifyKey;
    use HasBlogSlug;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('blogify.tables.term_translations', 'blogify_term_translations');
    }

    public function getConnectionName(): ?string
    {
        return config('blogify.database.connection') ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        /** @var class-string<Term> $model */
        $model = config('blogify.models.term', Term::class);

        return $this->belongsTo($model, 'term_id');
    }

    public function slugSourceAttribute(): string
    {
        return 'name';
    }

    /**
     * Term slugs are unique per owner, taxonomy and locale — so a tenant can
     * have both a "news" category and a "news" tag without colliding.
     *
     * @return array<int, string>
     */
    public function slugScopeColumns(): array
    {
        return ['owner_key', 'taxonomy', 'locale'];
    }

    /**
     * Copy the owner key and taxonomy down from the term.
     *
     * Both are denormalised so the unique slug index can span them, and both are
     * slug scope columns — so this runs ahead of slug generation, called by
     * HasBlogSlug. Filling them here also means a translation created through
     * the relation cannot end up carrying the column defaults.
     */
    protected function prepareSlugScope(): void
    {
        $needsOwner = $this->getAttribute('owner_key') === null;
        $needsTaxonomy = $this->getAttribute('taxonomy') === null;

        if (! $needsOwner && ! $needsTaxonomy && ! $this->isDirty('term_id')) {
            return;
        }

        // withoutGlobalScopes(): the parent is being fetched by primary key, and
        // its ownership is the very thing being read. Leaving OwnerScope on would
        // return null whenever no owner is bound — which is every command, queued
        // job and scheduler run — and the inherited columns would stay unset.
        $term = $this->relationLoaded('term')
            ? $this->getRelation('term')
            : $this->term()->withoutGlobalScopes()->first();

        if (! $term instanceof Term) {
            return;
        }

        $this->setAttribute('owner_key', $term->owner_key);
        $this->setAttribute('taxonomy', $term->taxonomy);
    }
}
