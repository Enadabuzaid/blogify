<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Models;

use Enadstack\Blogify\Casts\UnicodeJson;
use Enadstack\Blogify\Concerns\BelongsToBlogOwner;
use Enadstack\Blogify\Concerns\HasBlogifyKey;
use Enadstack\Blogify\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A stored file, owned like content is and optionally attached to a post or term.
 *
 * Alt text and captions are JSON rather than rows in a translations table. That
 * asymmetry with Post is deliberate: unlike a slug, alt text is never looked up
 * or uniquely indexed, so a separate table would add joins and buy nothing.
 *
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_key
 * @property string|null $attachable_type
 * @property string|null $attachable_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $file_name
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property array<string, string>|null $alt
 * @property array<string, string>|null $caption
 * @property array<string, mixed>|null $conversions
 * @property int $sort_order
 */
class Media extends Model
{
    use BelongsToBlogOwner;
    use HasBlogifyKey;
    use SoftDeletes;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('blogify.tables.media', 'blogify_media');
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
            // UnicodeJson rather than 'array': alt text and captions are largely
            // Arabic here, and the built-in cast would store it as \uXXXX escapes.
            'alt' => UnicodeJson::class,
            'caption' => UnicodeJson::class,
            'conversions' => UnicodeJson::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * What this file is attached to — a post, a term, or nothing.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * The public URL for this file, or for one of its conversions.
     */
    public function url(?string $conversion = null): string
    {
        $path = $this->path;

        if ($conversion !== null) {
            $conversions = $this->conversions ?? [];
            $path = is_string($conversions[$conversion] ?? null) ? $conversions[$conversion] : $this->path;
        }

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Alt text for a locale, walking the locale fallback chain.
     *
     * Falls back through the chain rather than returning null on the first miss,
     * because an image with no alt text at all is an accessibility regression.
     */
    public function alt(?string $locale = null): ?string
    {
        return $this->localized($this->alt, $locale);
    }

    /**
     * Caption for a locale, walking the locale fallback chain.
     */
    public function caption(?string $locale = null): ?string
    {
        return $this->localized($this->caption, $locale);
    }

    /**
     * @param  array<string, string>|null  $values
     */
    protected function localized(?array $values, ?string $locale): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        foreach (Locales::fallbackChain($locale) as $candidate) {
            if (isset($values[$candidate]) && $values[$candidate] !== '') {
                return $values[$candidate];
            }
        }

        // Any value beats none: an image with no alt text is an accessibility
        // regression, and the array is known non-empty by this point.
        return array_values($values)[0];
    }
}
