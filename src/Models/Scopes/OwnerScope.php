<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models\Scopes;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to the currently resolved owner.
 *
 * Active only in 'shared' mode. In 'single' mode there is nothing to separate;
 * in 'isolated' mode the database boundary has already done the separating and
 * a scope would only add a redundant predicate to every query.
 *
 * The match is strict — a tenant sees their own rows and nothing else. Platform
 * content is deliberately NOT folded in, because a doctor's blog showing the
 * platform's marketing articles is surprising rather than helpful. Ask for it
 * explicitly with ->platform(), or drop the scope with ->allOwners().
 *
 * The column is qualified with the table name so the scope survives joins.
 */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (config('blogify.tenancy.mode') !== 'shared') {
            return;
        }

        $builder->where(
            $model->getTable().'.owner_key',
            OwnerKey::for(app(OwnerResolver::class)->resolve())
        );
    }
}
