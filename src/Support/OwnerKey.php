<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds the non-null scalar that identifies a content owner.
 *
 * Why this exists, rather than scoping on owner_type + owner_id directly:
 * MySQL and PostgreSQL treat NULL as distinct from every other NULL inside a
 * unique index. A `unique(['owner_type', 'owner_id', 'slug'])` therefore does
 * NOT prevent two platform-level posts (both NULL/NULL) from sharing a slug —
 * the index silently accepts the duplicate.
 *
 * Every ownable table carries a denormalised `owner_key` instead: never null,
 * defaulting to the platform sentinel. Uniqueness then works identically on
 * MySQL, PostgreSQL and SQLite, and scoping reads one indexed column instead
 * of two.
 */
final class OwnerKey
{
    /**
     * The value stored for platform-level (unowned) content.
     */
    public const PLATFORM = '*';

    /**
     * Build the owner key for a model, or the platform sentinel for null.
     *
     * Uses getMorphClass() so a registered morph map produces short, stable
     * keys ("tenant:01HQ...") that survive class renames. Applications with
     * unmapped class names longer than ~180 characters should register a
     * morph map — the column is 191 characters wide.
     */
    public static function for(?Model $owner): string
    {
        if ($owner === null) {
            return self::PLATFORM;
        }

        return $owner->getMorphClass().':'.$owner->getKey();
    }

    /**
     * Whether a key refers to platform-level content.
     */
    public static function isPlatform(string $key): bool
    {
        return $key === self::PLATFORM;
    }
}
