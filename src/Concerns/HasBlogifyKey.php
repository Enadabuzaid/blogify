<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Concerns;

use Enadstack\Blogify\Support\Schema\BlogifySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Adapts a model to the configured primary key type.
 *
 * Applications key their tables differently — some use ULIDs throughout, others
 * plain auto-incrementing bigints — and the migrations honour that via
 * blogify.database.key_type. The models have to agree, which cannot be done by
 * conditionally `use`-ing Laravel's HasUlids, since trait imports are resolved
 * at compile time and the setting is only known at runtime.
 *
 * So key type is answered dynamically instead, and a generated key is assigned
 * on create when the configuration calls for one.
 */
trait HasBlogifyKey
{
    public static function bootHasBlogifyKey(): void
    {
        static::creating(function (Model $model): void {
            $type = BlogifySchema::keyType();

            if ($type === 'id') {
                return;
            }

            $keyName = $model->getKeyName();

            if ($model->getAttribute($keyName) === null) {
                $model->setAttribute(
                    $keyName,
                    $type === 'ulid' ? (string) Str::ulid() : (string) Str::uuid()
                );
            }
        });
    }

    public function getKeyType(): string
    {
        return BlogifySchema::keyType() === 'id' ? 'int' : 'string';
    }

    public function getIncrementing(): bool
    {
        return BlogifySchema::keyType() === 'id';
    }
}
