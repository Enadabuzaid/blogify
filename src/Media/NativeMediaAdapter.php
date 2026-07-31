<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Media;

use Enadstack\Blogify\Contracts\MediaAdapter;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores files on a Laravel filesystem disk, with rows in blogify_media.
 *
 * The default adapter, and the only one that needs no third-party package.
 * Files are grouped by owner so a tenant's uploads stay together on disk, which
 * makes per-tenant quota accounting and deletion straightforward.
 *
 * No image conversions are generated: that would mean an image-processing
 * dependency, and applications that want conversions already have
 * spatie/laravel-medialibrary — see SpatieMediaAdapter.
 */
class NativeMediaAdapter implements MediaAdapter
{
    public function store(UploadedFile $file, array $options = []): Media
    {
        $owner = array_key_exists('owner', $options)
            ? $options['owner']
            : app(OwnerResolver::class)->resolve();

        $disk = $options['disk'] ?? (string) config('blogify.media.disk', 'public');
        $collection = $options['collection'] ?? 'default';

        $path = $file->store($this->directoryFor($owner, $collection), $disk);

        if ($path === false) {
            throw new \RuntimeException(
                "Failed to store [{$file->getClientOriginalName()}] on disk [{$disk}]."
            );
        }

        $dimensions = $this->dimensions($file);

        /** @var class-string<Media> $model */
        $model = config('blogify.models.media', Media::class);

        $media = new $model([
            'collection' => $collection,
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'alt' => $options['alt'] ?? null,
            'caption' => $options['caption'] ?? null,
            'sort_order' => $options['sort_order'] ?? 0,
        ]);

        $media->assignOwner($owner);

        $attachable = $options['attachable'] ?? null;

        if ($attachable instanceof Model) {
            $media->attachable_type = $attachable->getMorphClass();
            $media->attachable_id = (string) $attachable->getKey();
        }

        $media->save();

        return $media;
    }

    public function url(Media $media, ?string $conversion = null): string
    {
        return $media->url($conversion);
    }

    public function delete(Media $media): void
    {
        $disk = Storage::disk($media->disk);

        if ($disk->exists($media->path)) {
            $disk->delete($media->path);
        }

        foreach ($media->conversions ?? [] as $path) {
            if (is_string($path) && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $media->delete();
    }

    /**
     * Where a file lives on disk.
     *
     * Grouping by owner keeps a tenant's uploads together, which makes quota
     * accounting and bulk deletion possible without scanning the table.
     */
    protected function directoryFor(?Model $owner, string $collection): string
    {
        $base = trim((string) config('blogify.media.directory', 'blogify'), '/');

        $segment = $owner === null
            ? 'platform'
            : $this->slugSegment($owner->getMorphClass()).'/'.$this->slugSegment((string) $owner->getKey());

        return $base.'/'.$segment.'/'.$this->slugSegment($collection);
    }

    /**
     * Make a value safe for a filesystem path.
     */
    protected function slugSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'unknown';
    }

    /**
     * Image dimensions, or nulls for anything that is not a readable image.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function dimensions(UploadedFile $file): array
    {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            return [null, null];
        }

        return [$size[0], $size[1]];
    }
}
