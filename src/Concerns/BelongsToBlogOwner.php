<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Concerns;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Exceptions\OwnerRequired;
use Enadstack\Blogify\Models\Scopes\OwnerScope;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Makes a model belong to a content owner — a tenant, a user, or the platform.
 *
 * Adds the OwnerScope for reads and stamps owner_type / owner_id / owner_key on
 * create. A null owner means platform-level content; owner_key then holds the
 * platform sentinel so unique indexes behave (see OwnerKey).
 *
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_key
 */
trait BelongsToBlogOwner
{
    public static function bootBelongsToBlogOwner(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(function (self $model): void {
            // Read the raw attribute array rather than the accessors. A caller
            // may deliberately assign platform ownership, and that has to be
            // distinguishable from having assigned nothing at all — which a null
            // check on the accessor cannot do, since both read as null.
            $attributes = $model->getAttributes();

            if (($attributes['owner_type'] ?? null) !== null) {
                // Morph columns were set explicitly. Honour them, but recompute
                // the denormalised key so it cannot disagree with them.
                $model->setAttribute('owner_key', $model->buildOwnerKey());

                return;
            }

            if (array_key_exists('owner_key', $attributes)) {
                // Explicitly assigned as platform content.
                return;
            }

            $owner = app(OwnerResolver::class)->resolve();

            if ($owner === null && config('blogify.tenancy.require_owner', false)
                && config('blogify.tenancy.mode') === 'shared') {
                throw OwnerRequired::for(static::class);
            }

            $model->assignOwner($owner);
        });
    }

    /**
     * The owning model, or null for platform-level content.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Point this record at an owner, or at the platform when given null.
     */
    public function assignOwner(?Model $owner): static
    {
        $this->owner_type = $owner?->getMorphClass();
        $this->owner_id = $owner === null ? null : (string) $owner->getKey();
        $this->owner_key = OwnerKey::for($owner);

        return $this;
    }

    /**
     * The owner key implied by the current morph columns.
     *
     * Derived from the columns rather than the relation so it works before the
     * record is saved and without touching the database.
     */
    public function buildOwnerKey(): string
    {
        $type = $this->getAttribute('owner_type');
        $id = $this->getAttribute('owner_id');

        if (! is_string($type) || $type === '' || $id === null || $id === '') {
            return OwnerKey::PLATFORM;
        }

        return $type.':'.$id;
    }

    /**
     * Whether this is platform-level content.
     */
    public function isPlatformContent(): bool
    {
        $key = $this->getAttribute('owner_key');

        return ! is_string($key) || OwnerKey::isPlatform($key);
    }

    /**
     * Restrict to one owner, ignoring whichever owner is currently resolved.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopeForOwner(Builder $query, ?Model $owner): Builder
    {
        return $query->withoutGlobalScope(OwnerScope::class)
            ->where($this->getTable().'.owner_key', OwnerKey::for($owner));
    }

    /**
     * Restrict to platform-level content.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnerScope::class)
            ->where($this->getTable().'.owner_key', OwnerKey::PLATFORM);
    }

    /**
     * Drop owner scoping entirely — for platform admin and moderation views.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopeAllOwners(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnerScope::class);
    }

    /**
     * Every owned row, across all owners, excluding platform content.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopeOwnedByAnyone(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnerScope::class)
            ->where($this->getTable().'.owner_key', '!=', OwnerKey::PLATFORM);
    }
}
