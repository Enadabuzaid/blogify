<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Support\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;

/**
 * Shared column definitions for Blogify's migrations.
 *
 * Two things are centralised here because getting either wrong is expensive to
 * undo once data exists.
 *
 * 1. Primary key type. Applications differ — some key everything with ULIDs,
 *    others use plain auto-incrementing bigints. key_type is read from config
 *    at migrate time so one set of migrations serves both.
 *
 * 2. Polymorphic owner and author columns. These are deliberately NOT built
 *    with $table->nullableMorphs(), which emits an unsignedBigInteger id
 *    column. Blogify has to store a reference to whatever model an application
 *    happens to use, and those are not all bigint-keyed: a tenant may have a
 *    26-character ULID while the user who wrote the post has a bigint. A
 *    numeric morph column silently truncates or rejects the former. The id is
 *    therefore always a string wide enough for a ULID, a UUID or a bigint.
 */
final class BlogifySchema
{
    /**
     * Width of every polymorphic key column.
     *
     * 40 characters holds a 36-character UUID, a 26-character ULID, or any
     * realistic bigint, with room to spare.
     */
    public const MORPH_KEY_LENGTH = 40;

    /**
     * Width of the denormalised owner key.
     *
     * A morph-mapped key ("tenant:01HQ...") is around 35 characters. 191 keeps
     * the column inside MySQL's utf8mb4 index limits while leaving room for
     * unmapped class names.
     */
    public const OWNER_KEY_LENGTH = 191;

    /**
     * Add the primary key, honouring blogify.database.key_type.
     */
    public static function key(Blueprint $table, string $column = 'id'): void
    {
        match (self::keyType()) {
            'ulid' => $table->ulid($column)->primary(),
            'uuid' => $table->uuid($column)->primary(),
            'id' => $table->id($column),
        };
    }

    /**
     * Add a foreign key column whose type matches the configured key type.
     *
     * Returns the column definition so the caller can chain ->nullable() before
     * applying the constraint via constrain().
     */
    public static function foreignKey(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::keyType()) {
            'ulid' => $table->foreignUlid($column),
            'uuid' => $table->foreignUuid($column),
            'id' => $table->unsignedBigInteger($column),
        };
    }

    /**
     * Add the owner columns: a string morph plus the denormalised owner key.
     *
     * owner_key is never null — platform-level rows carry the sentinel instead.
     * That is what makes unique indexes involving the owner behave consistently
     * across MySQL, PostgreSQL and SQLite, all of which treat NULL as distinct
     * from every other NULL inside a unique index.
     */
    public static function ownerColumns(Blueprint $table): void
    {
        $table->string('owner_type')->nullable();
        $table->string('owner_id', self::MORPH_KEY_LENGTH)->nullable();
        $table->string('owner_key', self::OWNER_KEY_LENGTH)->default('*');

        $table->index(['owner_type', 'owner_id']);
        $table->index('owner_key');
    }

    /**
     * Add a nullable string-keyed polymorphic reference.
     *
     * Used for the author byline and for media attachment, where the target
     * model's key type is likewise unknown.
     */
    public static function stringMorphs(Blueprint $table, string $name, bool $index = true): void
    {
        $table->string($name.'_type')->nullable();
        $table->string($name.'_id', self::MORPH_KEY_LENGTH)->nullable();

        if ($index) {
            $table->index([$name.'_type', $name.'_id']);
        }
    }

    /**
     * The configured key type, validated.
     *
     * @return 'id'|'ulid'|'uuid'
     */
    public static function keyType(): string
    {
        $type = (string) config('blogify.database.key_type', 'id');

        if (! in_array($type, ['id', 'ulid', 'uuid'], true)) {
            throw new InvalidArgumentException(
                "Invalid blogify.database.key_type [{$type}]. Expected one of: id, ulid, uuid."
            );
        }

        return $type;
    }

    /**
     * A table name from config.
     */
    public static function table(string $name): string
    {
        return (string) config("blogify.tables.{$name}", 'blogify_'.$name);
    }
}
