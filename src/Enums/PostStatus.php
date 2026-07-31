<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Enums;

/**
 * Lifecycle of a post.
 *
 * Stored as a plain string column rather than a database enum, so adding a
 * state later is a code change instead of an ALTER on a large table.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Whether content in this state should be publicly reachable.
     *
     * Scheduled is excluded: the row exists but its published_at has not
     * arrived yet. blogify:publish-scheduled promotes it when it does.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * The translation key for a human-readable label.
     *
     * Returns a key rather than a string so labels live in lang files and scale
     * past two languages.
     */
    public function label(): string
    {
        return 'blogify::blogify.status.'.$this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
