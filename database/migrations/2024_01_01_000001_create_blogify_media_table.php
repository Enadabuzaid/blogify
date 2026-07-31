<?php

declare(strict_types=1);

use Enadstack\Blogify\Support\Schema\BlogifySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(BlogifySchema::table('media'), function (Blueprint $table) {
            BlogifySchema::key($table);

            // Which blog this asset belongs to. Null owner = platform library.
            BlogifySchema::ownerColumns($table);

            // What the asset is attached to — a post, a term, or nothing at all
            // for a standalone library item.
            BlogifySchema::stringMorphs($table, 'attachable', index: false);

            $table->string('collection', 40)->default('default');

            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Alt text and captions are JSON rather than rows in a translations
            // table: unlike slugs they are never looked up or uniquely indexed,
            // so the extra table would buy nothing.
            $table->json('alt')->nullable();
            $table->json('caption')->nullable();

            // Populated by the media adapter — thumbnail paths and the like.
            $table->json('conversions')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at');

            $table->index(
                ['attachable_type', 'attachable_id', 'collection'],
                'blogify_media_attachable_collection_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('media'));
    }
};
