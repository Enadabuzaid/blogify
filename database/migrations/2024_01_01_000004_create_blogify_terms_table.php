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
        Schema::create(BlogifySchema::table('terms'), function (Blueprint $table) {
            BlogifySchema::key($table);

            // Terms are owned like posts are, so the platform can define shared
            // categories while each tenant adds their own.
            BlogifySchema::ownerColumns($table);

            // One table serves every taxonomy. 'category' and 'tag' ship by
            // default; adding 'specialty' or 'practice-area' is a config change
            // rather than a migration.
            $table->string('taxonomy', 32)->index();

            BlogifySchema::foreignKey($table, 'parent_id')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('extra')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at');

            $table->index(['owner_key', 'taxonomy'], 'blogify_terms_owner_taxonomy_idx');

            $table->foreign('parent_id', 'blogify_terms_parent_fk')
                ->references('id')
                ->on(BlogifySchema::table('terms'))
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('terms'));
    }
};
