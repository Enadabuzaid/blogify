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
        Schema::create(BlogifySchema::table('posts'), function (Blueprint $table) {
            BlogifySchema::key($table);

            // Whose blog this is. Null owner = platform-level content.
            BlogifySchema::ownerColumns($table);

            // Who wrote it. Distinct from the owner on purpose: the owner is
            // whose blog the post appears on, the author is the byline that
            // ends up in article:author and JSON-LD. On a platform blog the
            // owner is null while the author is a staff user.
            BlogifySchema::stringMorphs($table, 'author');

            $table->string('type', 32)->default('post')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->string('content_format', 16)->default('html');

            // Scheduling needs no extra column: status 'scheduled' plus a
            // published_at in the future.
            $table->timestamp('published_at')->nullable()->index();

            $table->boolean('featured')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('comments_enabled')->default(true);
            $table->boolean('noindex')->default(false);

            BlogifySchema::foreignKey($table, 'featured_media_id')->nullable();

            $table->string('canonical_url', 500)->nullable();
            $table->string('schema_type', 40)->default('Article');

            $table->unsignedSmallInteger('reading_time')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);

            $table->unsignedInteger('sort_order')->default(0);
            $table->json('extra')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at');

            $table->index(['owner_key', 'status', 'published_at'], 'blogify_posts_owner_status_pub_idx');
            $table->index(['owner_key', 'type', 'status'], 'blogify_posts_owner_type_status_idx');

            $table->foreign('featured_media_id', 'blogify_posts_featured_media_fk')
                ->references('id')
                ->on(BlogifySchema::table('media'))
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('posts'));
    }
};
