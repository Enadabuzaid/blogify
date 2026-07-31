<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a JSON column to an array, storing non-Latin text as characters rather
 * than as \uXXXX escapes.
 *
 * Laravel's built-in 'array' cast encodes without JSON_UNESCAPED_UNICODE, so
 * 'غرفة' is written as 'غرفة'. That round-trips correctly but
 * costs about six bytes per Arabic character instead of two, and makes the column
 * unreadable to anyone inspecting the database or grepping a dump — which matters
 * for a package whose content is expected to be largely Arabic.
 *
 * The set type is mixed rather than array: an attribute may legitimately be
 * assigned a pre-encoded JSON string, and that has to be normalised rather than
 * double-encoded.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
class UnicodeJson implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // Already encoded — decode and re-encode so the stored form is
            // consistent no matter how the value arrived.
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
