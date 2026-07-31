<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Stands in for an application model that already uses spatie/laravel-medialibrary.
 *
 * Bigint-keyed on purpose: medialibrary's own media.model_id is an integer column
 * by default, so this mirrors the case that works without schema surgery. An
 * application with ULID-keyed media owners has to widen that column first, which
 * is what SpatieMediaAdapter's docblock warns about.
 */
class TestGallery extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_galleries';

    protected $guarded = [];
}
