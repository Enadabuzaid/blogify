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
        Schema::create(BlogifySchema::table('post_translations'), function (Blueprint $table) {
            BlogifySchema::key($table);

            BlogifySchema::foreignKey($table, 'post_id');

            $table->string('locale', 10);

            // Denormalised from the post. Two reasons, both load-bearing:
            //
            //  - It makes the per-owner unique slug index below possible at all.
            //    The owner lives on the post, and a unique index cannot span
            //    tables.
            //  - Front-end route resolution becomes a single-table lookup:
            //    where owner_key = ? and locale = ? and slug = ?, fully indexed,
            //    no join.
            $table->string('owner_key', BlogifySchema::OWNER_KEY_LENGTH)->default('*');

            $table->string('title');
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description', 500)->nullable();

            // Lets a locale be held back — publish the Arabic while the English
            // translation is still being written, or vice versa.
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->index('owner_key');
            $table->index(['locale', 'is_published']);

            $table->unique(['post_id', 'locale'], 'blogify_post_tr_post_locale_unique');
            $table->unique(['owner_key', 'locale', 'slug'], 'blogify_post_tr_owner_locale_slug_unique');

            $table->foreign('post_id', 'blogify_post_tr_post_fk')
                ->references('id')
                ->on(BlogifySchema::table('posts'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('post_translations'));
    }
};
