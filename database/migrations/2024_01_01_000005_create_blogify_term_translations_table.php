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
        Schema::create(BlogifySchema::table('term_translations'), function (Blueprint $table) {
            BlogifySchema::key($table);

            BlogifySchema::foreignKey($table, 'term_id');

            $table->string('locale', 10);

            // Both denormalised from the term, for the same reasons owner_key is
            // denormalised onto post translations: the unique index below needs
            // them in one table, and slug lookups avoid a join.
            $table->string('owner_key', BlogifySchema::OWNER_KEY_LENGTH)->default('*');
            $table->string('taxonomy', 32);

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['owner_key', 'taxonomy', 'locale'], 'blogify_term_tr_owner_tax_loc_idx');

            $table->unique(['term_id', 'locale'], 'blogify_term_tr_term_locale_unique');
            $table->unique(
                ['owner_key', 'taxonomy', 'locale', 'slug'],
                'blogify_term_tr_owner_tax_loc_slug_unique'
            );

            $table->foreign('term_id', 'blogify_term_tr_term_fk')
                ->references('id')
                ->on(BlogifySchema::table('terms'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(BlogifySchema::table('term_translations'));
    }
};
