<?php

declare(strict_types=1);

use Enadstack\Blogify\Media\NativeMediaAdapter;
use Enadstack\Blogify\Models\Media;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Models\PostTranslation;
use Enadstack\Blogify\Models\SlugHistory;
use Enadstack\Blogify\Models\Term;
use Enadstack\Blogify\Models\TermTranslation;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Blogify calls the thing that owns a post its "owner". The owner may be
    | a tenant model, a user, or nothing at all — a NULL owner means the row
    | is platform-level content (your own marketing blog).
    |
    | Modes:
    |
    |   'single'   No scoping. Every row is written with a NULL owner. Use
    |              this for a plain website with one blog.
    |
    |   'shared'   One database, many owners. A global scope restricts every
    |              query to the currently resolved owner. Platform rows are
    |              NOT visible to a tenant — ask for them explicitly with
    |              ->platform() or ->allOwners().
    |
    |   'isolated' Database-per-tenant. The global scope is a no-op because
    |              the database boundary already isolates rows, but owner
    |              columns are still stamped so platform content inside a
    |              tenant database stays distinguishable.
    |
    | The owner columns exist in every mode, so a single-tenant install can
    | become multi-tenant later without a new migration.
    |
    */

    'tenancy' => [

        'mode' => env('BLOGIFY_TENANCY_MODE', 'single'),

        /*
         | The class that resolves the current owner. Must implement
         | Enadstack\Blogify\Contracts\OwnerResolver. Ships with:
         |
         |   NullOwnerResolver       Always null. Single-tenant and isolated.
         |   ContainerOwnerResolver  Reads a container binding (see below).
         |   AuthOwnerResolver       Reads a relation off the authed user.
         |   CallbackOwnerResolver   Uses Blogify::resolveOwnerUsing(...).
         |   StanclOwnerResolver     stancl/tenancy.
         |   SpatieOwnerResolver     spatie/laravel-multitenancy.
         |
         | Note this is a class NAME, never a closure — a closure here would
         | break `php artisan config:cache`. To resolve the owner with your
         | own logic, use CallbackOwnerResolver and register the callback in
         | a service provider:
         |
         |   Blogify::resolveOwnerUsing(fn () => $this->currentTenant());
         */
        'resolver' => NullOwnerResolver::class,

        /*
         | Used by ContainerOwnerResolver. The container key your application
         | binds the current tenant to, e.g.
         |
         |   app()->instance('currentTenant', $tenant);
         */
        'container_key' => 'currentTenant',

        /*
         | Used by AuthOwnerResolver. The relation or attribute on your user
         | model that points at the owner — 'tenant', 'lawyer', 'clinic'. If
         | the relation is missing or null, the user itself becomes the owner
         | when `fallback_to_user` is true.
         */
        'user_relation' => 'tenant',
        'fallback_to_user' => false,

        /*
         | When true, writing a row with no resolved owner throws while a
         | non-null resolver is active. Useful for catching bugs early in
         | shared mode; leave false if the platform also publishes content.
         */
        'require_owner' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | key_type controls the primary key of Blogify's own tables. Set it to
    | match your application's convention — 'ulid' if your other tables use
    | ULIDs, 'id' for plain auto-incrementing bigints.
    |
    | This is read inside the migrations, so set it BEFORE running migrate.
    | It cannot be changed once data exists without a manual migration.
    |
    | Owner and author columns are always string(40) regardless, so a model
    | with a ULID key and a model with a bigint key can both own content.
    |
    */

    'database' => [
        'key_type' => env('BLOGIFY_KEY_TYPE', 'id'), // id | ulid | uuid
        'connection' => env('BLOGIFY_DB_CONNECTION', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Blogify stores translations in dedicated `*_translations` tables, one
    | row per locale, so each locale gets its own indexed slug and its own
    | meta fields. `fallback` is used when a requested locale has no row.
    |
    | `rtl` drives Locales::direction(), which your views can use for the
    | dir attribute. Direction is never stored in the database.
    |
    */

    'locales' => [
        'supported' => ['en', 'ar'],
        'default' => env('BLOGIFY_DEFAULT_LOCALE', 'en'),
        'fallback' => env('BLOGIFY_FALLBACK_LOCALE', 'en'),
        'rtl' => ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'ckb', 'dv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Every table is prefixed `blogify_` by default to avoid colliding with
    | tables your application already owns. Changing these after data exists
    | requires a manual migration.
    |
    */

    'tables' => [
        'media' => 'blogify_media',
        'posts' => 'blogify_posts',
        'post_translations' => 'blogify_post_translations',
        'terms' => 'blogify_terms',
        'term_translations' => 'blogify_term_translations',
        'post_term' => 'blogify_post_term',
        'slug_history' => 'blogify_slug_history',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Subclass any of these and point the config at your own class to add
    | application-specific behaviour without forking the package.
    |
    */

    'models' => [
        'post' => Post::class,
        'post_translation' => PostTranslation::class,
        'term' => Term::class,
        'term_translation' => TermTranslation::class,
        'media' => Media::class,
        'slug_history' => SlugHistory::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    |
    | post_types are the values allowed in `blogify_posts.type`, and
    | taxonomies are the values allowed in `blogify_terms.taxonomy`. Both
    | are plain strings in the database, so adding one is a config change
    | rather than a migration.
    |
    */

    'content' => [
        'post_types' => ['post', 'page'],
        'taxonomies' => ['category', 'tag'],
        'default_post_type' => 'post',
        'words_per_minute' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | NativeMediaAdapter stores files on the configured disk and rows in
    | blogify_media. SpatieMediaAdapter delegates to spatie/laravel-medialibrary
    | if you already depend on it.
    |
    */

    'media' => [
        'adapter' => NativeMediaAdapter::class,
        'disk' => env('BLOGIFY_MEDIA_DISK', 'public'),
        'directory' => 'blogify',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    |
    | track_slug_history records every slug a post has ever had, so renaming
    | one can 301 instead of 404. See Blogify::resolveHistoricalSlug().
    |
    */

    'seo' => [
        'track_slug_history' => true,
        'site_name' => env('BLOGIFY_SITE_NAME', null),
        'default_schema_type' => 'Article',
        'sitemap' => [
            'enabled' => true,
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Blogify ships headless — no routes, no views, no controllers. Your
    | application owns rendering. This block exists so a future companion
    | package has somewhere to read its settings from.
    |
    | If you do register your own blog routes, mind the ordering: a catch-all
    | such as Route::get('/{slug?}', ...) will swallow /blog unless your blog
    | routes are registered first.
    |
    */

    'routes' => [
        'enabled' => false,
        'prefix' => 'blog',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Commands
    |--------------------------------------------------------------------------
    |
    | Set publish_cron to a cron expression to have blogify:publish-scheduled
    | registered with your application's scheduler. Set it to null to disable
    | and call the command yourself.
    |
    */

    'schedule' => [
        'publish_cron' => '* * * * *',
    ],

];
