<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Concerns;

use Enadstack\Blogify\Support\Slugger;
use Illuminate\Database\Eloquent\Model;

/**
 * Generates a URL slug that is unique within its own scope.
 *
 * Slugs live on the translations tables, so uniqueness is per owner and per
 * locale — two different tenants may legitimately both publish "about-us", and
 * so may the Arabic and English versions of the same post. The scope columns
 * therefore come from the model rather than being fixed here.
 *
 * Uniqueness is enforced by a database index as well; this only spares callers
 * from handling the collision themselves.
 */
trait HasBlogSlug
{
    /**
     * The attribute a slug is derived from when none is supplied.
     */
    abstract public function slugSourceAttribute(): string;

    /**
     * Columns a slug must be unique within.
     *
     * @return array<int, string>
     */
    abstract public function slugScopeColumns(): array;

    public static function bootHasBlogSlug(): void
    {
        static::saving(function (Model $model): void {
            /** @var static $model */

            // Ordering matters. Uniqueness is checked against the scope columns,
            // and on a translation those are denormalised copies that may not be
            // populated yet — a slug de-duplicated against a null owner_key
            // would pass here and then violate the index on insert. Giving the
            // model one hook to fill them first keeps that ordering explicit
            // instead of depending on which trait booted first.
            $model->prepareSlugScope();
            $model->ensureSlug();
        });
    }

    /**
     * Populate the scope columns a slug must be unique within.
     *
     * Override when any of slugScopeColumns() is denormalised from a parent and
     * therefore not set by the caller.
     */
    protected function prepareSlugScope(): void
    {
        //
    }

    /**
     * Fill in or de-duplicate the slug before saving.
     */
    public function ensureSlug(): void
    {
        $source = (string) ($this->getAttribute('slug') ?: $this->getAttribute($this->slugSourceAttribute()) ?? '');

        if ($source === '') {
            return;
        }

        $candidate = Slugger::make($source);

        // Nothing to do when an already-unique slug has not changed.
        if ($candidate === $this->getOriginal('slug') && $this->exists) {
            $this->setAttribute('slug', $candidate);

            return;
        }

        $this->setAttribute('slug', Slugger::unique(
            $source,
            fn (string $slug): bool => $this->slugExists($slug)
        ));
    }

    /**
     * Whether a slug is already taken within this record's scope.
     */
    protected function slugExists(string $slug): bool
    {
        $query = static::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug);

        foreach ($this->slugScopeColumns() as $column) {
            $query->where($column, $this->getAttribute($column));
        }

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        return $query->exists();
    }
}
