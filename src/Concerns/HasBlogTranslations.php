<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Concerns;

use Enadstack\Blogify\Support\Locales;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reads and writes per-locale content held in a dedicated translations table.
 *
 * Blogify stores translations as rows rather than as JSON columns, because a
 * slug in a JSON column cannot be uniquely indexed and cannot be looked up
 * without an unindexed JSON_EXTRACT scan. One row per locale gives every
 * language its own indexed slug, its own meta fields, and its own publish flag.
 *
 * Implementing models must provide translationModel() and translationForeignKey().
 *
 * @property-read Collection<int, Model> $translations
 */
trait HasBlogTranslations
{
    /**
     * The model class holding this model's translations.
     *
     * @return class-string<Model>
     */
    abstract public function translationModel(): string;

    /**
     * The column on the translations table pointing back at this model.
     */
    abstract public function translationForeignKey(): string;

    /**
     * Every translation of this record.
     *
     * @return HasMany<Model, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel(), $this->translationForeignKey());
    }

    /**
     * The translation for a locale, walking the fallback chain.
     *
     * Returns null only when the record has no translations at all. Reads from
     * the loaded relation when it is available, so rendering a list of posts
     * with `->with('translations')` costs one query rather than one per post.
     */
    public function translation(?string $locale = null): ?Model
    {
        foreach (Locales::fallbackChain($locale) as $candidate) {
            $match = $this->findTranslation($candidate);

            if ($match !== null) {
                return $match;
            }
        }

        // Last resort: any translation beats rendering nothing.
        return $this->loadedTranslations()->first();
    }

    /**
     * A translated attribute, walking the fallback chain.
     */
    public function t(string $attribute, ?string $locale = null): mixed
    {
        return $this->translation($locale)?->getAttribute($attribute);
    }

    /**
     * Whether a translation exists for exactly this locale, no fallback.
     */
    public function hasTranslation(?string $locale = null): bool
    {
        return $this->findTranslation(Locales::resolve($locale)) !== null;
    }

    /**
     * The locales this record has been translated into.
     *
     * @return array<int, string>
     */
    public function translatedLocales(): array
    {
        return $this->loadedTranslations()
            ->pluck('locale')
            ->filter()
            ->map(static fn (mixed $locale): string => (string) $locale)
            ->values()
            ->all();
    }

    /**
     * Create or update the translation for a locale.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setTranslation(string $locale, array $attributes): Model
    {
        $locale = Locales::resolve($locale);

        /** @var Model $translation */
        $translation = $this->translations()->updateOrCreate(
            ['locale' => $locale],
            $attributes
        );

        // Keep the loaded relation honest, so a caller that reads the value back
        // in the same request does not see stale data.
        $this->unsetRelation('translations');

        return $translation;
    }

    /**
     * Create or update several locales at once.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function setTranslations(array $translations): static
    {
        foreach ($translations as $locale => $attributes) {
            $this->setTranslation($locale, $attributes);
        }

        return $this;
    }

    /**
     * Find a translation for one exact locale.
     */
    protected function findTranslation(string $locale): ?Model
    {
        return $this->loadedTranslations()
            ->first(static fn (Model $translation): bool => $translation->getAttribute('locale') === $locale);
    }

    /**
     * The translations relation, loading it if it has not been loaded yet.
     *
     * @return Collection<int, Model>
     */
    protected function loadedTranslations(): Collection
    {
        if (! $this->relationLoaded('translations')) {
            // An unsaved record has nothing to load; querying would return every
            // orphaned translation row.
            if (! $this->exists) {
                /** @var Collection<int, Model> $empty */
                $empty = new Collection;

                return $empty;
            }

            $this->load('translations');
        }

        /** @var Collection<int, Model> $translations */
        $translations = $this->getRelation('translations');

        return $translations;
    }
}
