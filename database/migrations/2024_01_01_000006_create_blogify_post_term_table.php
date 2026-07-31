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
        Schema::create(BlogifySchema::table('post_term'), function (Blueprint $table) {
            BlogifySchema::foreignKey($table, 'post_id');
            BlogifySchema::foreignKey($table, 'term_id');

            $table->unsignedInteger('sort_order')->default(0);

            // No surrogate key: the pair is the identity. It also has to stay
            // that way — a generated primary key such as a ULID would never be
            // populated, because belongsToMany::attach() writes the pivot
            // through the query builder rather than through a model.
            $table->primary(['post_id', 'term_id'], 'blogify_post_term_primary');
            $table->index('term_id');

            $table->foreign('post_id', 'blogify_post_term_post_fk')
                ->references('id')
                ->on(BlogifySchema::table('posts'))
                ->cascadeOnDelete();

            $table->foreign('term_id', 'blogify_post_term_term_fk')
                ->references('id')
                ->on(BlogifySchema::table('terms'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('post_term'));
    }
};
