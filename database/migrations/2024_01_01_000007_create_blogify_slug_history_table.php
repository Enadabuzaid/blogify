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
        Schema::create(BlogifySchema::table('slug_history'), function (Blueprint $table) {
            BlogifySchema::key($table);

            BlogifySchema::foreignKey($table, 'post_id');

            $table->string('locale', 10);
            $table->string('owner_key', BlogifySchema::OWNER_KEY_LENGTH)->default('*');
            $table->string('slug', 191);

            $table->timestamps();

            // A retired slug resolves to exactly one post, so the lookup that
            // powers a 301 redirect is a unique index hit.
            $table->unique(['owner_key', 'locale', 'slug'], 'blogify_slug_history_lookup_unique');
            $table->index('post_id');

            $table->foreign('post_id', 'blogify_slug_history_post_fk')
                ->references('id')
                ->on(BlogifySchema::table('posts'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('slug_history'));
    }
};
