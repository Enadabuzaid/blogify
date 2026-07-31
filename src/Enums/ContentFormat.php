<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Enums;

/**
 * How a post body is encoded.
 *
 * Blogify does not render bodies — this only tells the consuming application
 * how to interpret the column.
 */
enum ContentFormat: string
{
    case Html = 'html';
    case Markdown = 'markdown';
    case Blocks = 'blocks';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
