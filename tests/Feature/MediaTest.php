<?php

declare(strict_types=1);

use Enadstack\Blogify\Contracts\MediaAdapter;
use Enadstack\Blogify\Media\NativeMediaAdapter;
use Enadstack\Blogify\Media\SpatieMediaAdapter;
use Enadstack\Blogify\Models\Media;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\OwnerKey;
use Enadstack\Blogify\Tests\Fixtures\TestGallery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('resolves the adapter named in config', function () {
    expect(app(MediaAdapter::class))->toBeInstanceOf(NativeMediaAdapter::class);
});

it('stores a file and records it', function () {
    $media = app(MediaAdapter::class)->store(
        UploadedFile::fake()->image('scan.jpg', 800, 600)
    );

    expect($media->exists)->toBeTrue()
        ->and($media->disk)->toBe('public')
        ->and($media->file_name)->toBe('scan.jpg')
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->size)->toBeGreaterThan(0)
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(600);

    Storage::disk('public')->assertExists($media->path);
});

it('leaves dimensions null for a non-image', function () {
    $media = app(MediaAdapter::class)->store(
        UploadedFile::fake()->create('notes.pdf', 16, 'application/pdf')
    );

    expect($media->width)->toBeNull()
        ->and($media->height)->toBeNull()
        ->and($media->mime_type)->toBe('application/pdf');
});

/*
 * Grouping uploads by owner on disk is what makes per-tenant quota accounting
 * and bulk deletion possible without scanning the table.
 */
it('files uploads under a per-owner directory', function () {
    $tenant = $this->makeTenant('Clinic A');

    $owned = app(MediaAdapter::class)->store(
        UploadedFile::fake()->image('a.jpg'),
        ['owner' => $tenant, 'collection' => 'gallery']
    );

    $platform = app(MediaAdapter::class)->store(UploadedFile::fake()->image('b.jpg'));

    expect($owned->path)->toContain((string) $tenant->getKey())
        ->and($owned->path)->toContain('gallery')
        ->and($platform->path)->toContain('platform');
});

it('sanitises the owner class into a safe path segment', function () {
    $tenant = $this->makeTenant('Clinic A');

    $media = app(MediaAdapter::class)->store(
        UploadedFile::fake()->image('a.jpg'),
        ['owner' => $tenant]
    );

    // Backslashes from a namespaced class name would otherwise create bogus
    // directory nesting on disk.
    expect($media->path)->not->toContain('\\');
});

it('stamps the resolved owner when none is given', function () {
    $tenant = $this->makeTenant('Clinic A');
    $this->actingForOwner($tenant);

    $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('a.jpg'));

    expect($media->owner_key)->toBe(OwnerKey::for($tenant));
});

it('attaches media to a post', function () {
    $post = Post::query()->create(['type' => 'post']);

    $media = app(MediaAdapter::class)->store(
        UploadedFile::fake()->image('hero.jpg'),
        ['attachable' => $post, 'collection' => 'hero']
    );

    expect($media->attachable_type)->toBe($post->getMorphClass())
        ->and($media->attachable_id)->toBe((string) $post->getKey())
        ->and($post->fresh()->media)->toHaveCount(1)
        ->and($media->attachable->is($post))->toBeTrue();
});

it('serves as a post\'s featured image', function () {
    $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('hero.jpg'));

    $post = Post::query()->create(['type' => 'post', 'featured_media_id' => $media->getKey()]);

    expect($post->fresh()->featuredMedia->is($media))->toBeTrue()
        ->and($post->fresh()->featuredMedia->url())->toContain($media->path);
});

it('clears the featured image when the media is deleted', function () {
    $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('hero.jpg'));
    $post = Post::query()->create(['type' => 'post', 'featured_media_id' => $media->getKey()]);

    $media->forceDelete();

    expect($post->fresh()->featured_media_id)->toBeNull();
});

it('removes the file from disk on delete', function () {
    $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('a.jpg'));
    $path = $media->path;

    app(MediaAdapter::class)->delete($media);

    Storage::disk('public')->assertMissing($path);
    expect(Media::query()->count())->toBe(0);
});

describe('localised alt text', function () {
    it('reads alt and caption per locale', function () {
        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('a.jpg'),
            [
                'alt' => ['en' => 'A clinic room', 'ar' => 'غرفة في العيادة'],
                'caption' => ['en' => 'Our new wing', 'ar' => 'الجناح الجديد'],
            ]
        );

        expect($media->alt('en'))->toBe('A clinic room')
            ->and($media->alt('ar'))->toBe('غرفة في العيادة')
            ->and($media->caption('ar'))->toBe('الجناح الجديد');
    });

    /*
     * An image with no alt text at all is an accessibility regression, so a
     * missing locale walks the fallback chain rather than returning null.
     */
    it('falls back rather than returning nothing', function () {
        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('a.jpg'),
            ['alt' => ['en' => 'A clinic room']]
        );

        expect($media->alt('ar'))->toBe('A clinic room');
    });

    it('returns null when there is no alt text at all', function () {
        $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('a.jpg'));

        expect($media->alt('en'))->toBeNull()
            ->and($media->caption('en'))->toBeNull();
    });

    it('stores Arabic unescaped in the JSON column', function () {
        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('a.jpg'),
            ['alt' => ['ar' => 'غرفة في العيادة']]
        );

        $raw = DB::table($media->getTable())->where('id', $media->getKey())->value('alt');

        expect($raw)->toContain('غرفة');
    });
});

describe('owner scoping', function () {
    it('hides one owner\'s media from another', function () {
        $a = $this->makeTenant('Clinic A');
        $b = $this->makeTenant('Clinic B');

        $this->actingForOwner($a);
        app(MediaAdapter::class)->store(UploadedFile::fake()->image('a.jpg'));

        $this->actingForOwner($b);
        app(MediaAdapter::class)->store(UploadedFile::fake()->image('b.jpg'));

        expect(Media::query()->count())->toBe(1);

        $this->actingForOwner($a);
        expect(Media::query()->count())->toBe(1)
            ->and(Media::query()->allOwners()->count())->toBe(2);
    });
});

describe('SpatieMediaAdapter', function () {
    beforeEach(function () {
        config()->set('blogify.media.adapter', SpatieMediaAdapter::class);
        $this->app->forgetInstance(MediaAdapter::class);
    });

    it('detects the installed package', function () {
        expect(SpatieMediaAdapter::isAvailable())->toBeTrue()
            ->and(app(MediaAdapter::class))->toBeInstanceOf(SpatieMediaAdapter::class);
    });

    /*
     * The point of this adapter: medialibrary owns the file so existing
     * conversions keep working, while the blogify_media row stays the index the
     * rest of the package reads.
     */
    it('delegates storage to medialibrary and indexes it', function () {
        $gallery = TestGallery::query()->create(['name' => 'Clinic tour']);

        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('room.jpg', 640, 480),
            ['attachable' => $gallery, 'collection' => 'photos', 'alt' => ['ar' => 'غرفة']]
        );

        expect($media->exists)->toBeTrue()
            ->and($media->collection)->toBe('photos')
            ->and($media->file_name)->toBe('room.jpg')
            ->and($media->attachable_id)->toBe((string) $gallery->getKey())
            ->and($media->alt('ar'))->toBe('غرفة')
            ->and($gallery->getMedia('photos'))->toHaveCount(1);
    });

    /*
     * The blogify row has to point back at the Spatie record, or deleting through
     * Blogify would leave the file and its conversions orphaned on disk.
     */
    it('records the medialibrary id so deletion can cascade', function () {
        $gallery = TestGallery::query()->create(['name' => 'Clinic tour']);

        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('room.jpg'),
            ['attachable' => $gallery, 'collection' => 'photos']
        );

        expect($media->conversions)->toHaveKey('spatie_media_id');

        app(MediaAdapter::class)->delete($media);

        expect($gallery->fresh()->getMedia('photos'))->toHaveCount(0)
            ->and(Media::query()->count())->toBe(0);
    });

    /*
     * An attachable that does not implement HasMedia, or no attachable at all,
     * cannot hold Spatie media — those fall through to plain disk storage rather
     * than failing.
     */
    it('falls back to disk storage for a non-media attachable', function () {
        $post = Post::query()->create(['type' => 'post']);

        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('hero.jpg'),
            ['attachable' => $post]
        );

        expect($media->exists)->toBeTrue()
            ->and($media->conversions)->toBeNull();

        Storage::disk('public')->assertExists($media->path);
    });

    it('falls back to disk storage for a standalone upload', function () {
        $media = app(MediaAdapter::class)->store(UploadedFile::fake()->image('loose.jpg'));

        expect($media->exists)->toBeTrue()
            ->and($media->attachable_type)->toBeNull();

        Storage::disk('public')->assertExists($media->path);
    });

    it('stamps the resolved owner on delegated media', function () {
        $tenant = $this->makeTenant('Clinic A');
        $this->actingForOwner($tenant);

        $gallery = TestGallery::query()->create(['name' => 'Clinic tour']);

        $media = app(MediaAdapter::class)->store(
            UploadedFile::fake()->image('room.jpg'),
            ['attachable' => $gallery]
        );

        expect($media->owner_key)->toBe(OwnerKey::for($tenant));
    });
});
