<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Media;

use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Delegates storage to spatie/laravel-medialibrary while keeping Blogify's own
 * media row as the index.
 *
 * For applications that already depend on medialibrary and have conversions
 * configured. The Spatie media record does the file handling; the blogify_media
 * row records ownership, collection, alt text and the conversion URLs, so the
 * rest of the package needs no knowledge of which adapter is in play.
 *
 * spatie/laravel-medialibrary is not a requirement of this package, so its
 * absence is reported as a configuration error rather than a class-not-found.
 *
 * One thing worth knowing when wiring this up: medialibrary's media.model_id is
 * an integer column by default, so a ULID-keyed attachable needs that column
 * widened to a string first.
 */
class SpatieMediaAdapter extends NativeMediaAdapter
{
    protected const INTERACTS_WITH_MEDIA = '\Spatie\MediaLibrary\HasMedia';

    public function store(UploadedFile $file, array $options = []): Media
    {
        $this->guardAvailable();

        $attachable = $options['attachable'] ?? null;

        // Only an attachable that implements HasMedia can hold Spatie media.
        // Standalone library uploads and non-media models fall through to disk
        // storage, which is the same file in the same place either way.
        $contract = self::INTERACTS_WITH_MEDIA;

        if (! $attachable instanceof Model || ! $attachable instanceof $contract) {
            return parent::store($file, $options);
        }

        $collection = $options['collection'] ?? 'default';

        // The original filename is kept rather than hashed, so blogify_media.file_name
        // means the same thing under either adapter. Collisions are not a concern:
        // medialibrary stores each record in its own id-based directory.
        $spatieMedia = $attachable
            ->addMedia($file->getRealPath())
            ->usingFileName($file->getClientOriginalName())
            ->withCustomProperties(['blogify' => true])
            ->toMediaCollection($collection, $options['disk'] ?? (string) config('blogify.media.disk', 'public'));

        return $this->recordFor($spatieMedia, $attachable, $options);
    }

    public function delete(Media $media): void
    {
        // The Spatie record owns the files; deleting it removes the original and
        // every generated conversion.
        $spatieId = $media->conversions['spatie_media_id'] ?? null;

        if ($spatieId !== null && $this->isAvailable()) {
            /** @var class-string<Model> $mediaModel */
            $mediaModel = config('media-library.media_model', '\Spatie\MediaLibrary\MediaCollections\Models\Media');

            $mediaModel::query()->whereKey($spatieId)->get()->each->delete();

            $media->delete();

            return;
        }

        parent::delete($media);
    }

    /**
     * Build the blogify_media row that indexes a Spatie media record.
     *
     * @param  array<string, mixed>  $options
     */
    protected function recordFor(SpatieMedia $spatieMedia, Model $attachable, array $options): Media
    {
        $owner = array_key_exists('owner', $options)
            ? $options['owner']
            : app(OwnerResolver::class)->resolve();

        /** @var class-string<Media> $model */
        $model = config('blogify.models.media', Media::class);

        $conversions = ['spatie_media_id' => $spatieMedia->getKey()];

        foreach ($this->generatedConversions($spatieMedia) as $name) {
            $conversions[$name] = $spatieMedia->getUrl($name);
        }

        $media = new $model([
            'collection' => $options['collection'] ?? 'default',
            'disk' => (string) $spatieMedia->disk,
            'path' => (string) $spatieMedia->getPathRelativeToRoot(),
            'file_name' => (string) $spatieMedia->file_name,
            'mime_type' => $spatieMedia->mime_type,
            'size' => (int) $spatieMedia->size,
            'alt' => $options['alt'] ?? null,
            'caption' => $options['caption'] ?? null,
            'conversions' => $conversions,
            'sort_order' => $options['sort_order'] ?? 0,
        ]);

        $media->assignOwner($owner);
        $media->attachable_type = $attachable->getMorphClass();
        $media->attachable_id = (string) $attachable->getKey();
        $media->save();

        return $media;
    }

    /**
     * Names of the conversions medialibrary actually generated.
     *
     * @return array<int, string>
     */
    protected function generatedConversions(SpatieMedia $spatieMedia): array
    {
        return array_keys(array_filter($spatieMedia->generated_conversions ?? []));
    }

    public static function isAvailable(): bool
    {
        return interface_exists(self::INTERACTS_WITH_MEDIA);
    }

    protected function guardAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'blogify.media.adapter is set to SpatieMediaAdapter but '
                .'spatie/laravel-medialibrary is not installed. Either run '
                .'`composer require spatie/laravel-medialibrary` or switch the '
                .'adapter back to NativeMediaAdapter.'
            );
        }
    }
}
