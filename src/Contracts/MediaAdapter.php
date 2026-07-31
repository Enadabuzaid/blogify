<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Contracts;

use Enadstack\Blogify\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Stores and retrieves the files attached to content.
 *
 * Exists as a contract because applications disagree about media. Some already
 * depend on spatie/laravel-medialibrary and want Blogify to reuse the
 * conversions they have configured; others want no such dependency. Neither is
 * imposed — the default implementation writes to a Laravel disk and needs
 * nothing beyond the framework.
 */
interface MediaAdapter
{
    /**
     * Store an uploaded file and return the Media record describing it.
     *
     * @param  array{
     *     owner?: Model|null,
     *     attachable?: Model|null,
     *     collection?: string,
     *     disk?: string,
     *     alt?: array<string, string>,
     *     caption?: array<string, string>,
     *     sort_order?: int,
     * }  $options
     */
    public function store(UploadedFile $file, array $options = []): Media;

    /**
     * The public URL for a stored file, or for one of its conversions.
     */
    public function url(Media $media, ?string $conversion = null): string;

    /**
     * Remove a stored file and its record.
     */
    public function delete(Media $media): void;
}
