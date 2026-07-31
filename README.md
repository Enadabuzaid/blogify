# Blogify

A headless content engine for Laravel SaaS products. Articles, taxonomy, media and SEO, for both platform-level and per-tenant content, with first-class RTL/Arabic support.

**It ships no design.** No views, no routes, no controllers, no admin panel — your app owns rendering.

```bash
composer require enadstack/blogify
php artisan blogify:install
php artisan migrate
```

---

## The problem it solves

The same engine has to serve three shapes without forking:

| Shape | Who owns a post |
|---|---|
| A single site with a blog | nobody — every post is "the site's" |
| A platform writing its own SEO content | the platform |
| Each tenant writing their own | that doctor, lawyer, or clinic |

Blogify models this with **one nullable polymorphic owner**. `NULL` means platform-level. Anything else — a `Tenant`, a `User`, a `Clinic` — owns its own blog.

The owner columns exist in every mode, so **a single-tenant site can become multi-tenant later without a new migration**.

---

## Tenancy

### Three modes

```php
'tenancy' => ['mode' => 'single'],   // single | shared | isolated
```

| mode | scoping | owner columns |
|---|---|---|
| `single` | none | always `NULL` |
| `shared` | one database, a global scope per owner | populated |
| `isolated` | none — the tenant database already isolates | still populated |

`isolated` is for database-per-tenant setups. Publish the migrations into the tenant path:

```bash
php artisan vendor:publish --tag=blogify-migrations-tenant
```

### Resolvers

Blogify takes **no dependency on any tenancy package**. Pick the resolver that matches how your app already works:

| Resolver | Use when |
|---|---|
| `NullOwnerResolver` | single-tenant, or database-per-tenant |
| `ContainerOwnerResolver` | a middleware binds the tenant: `app()->instance('currentTenant', $tenant)` |
| `AuthOwnerResolver` | the owner hangs off the user: `$user->tenant`, `$user->lawyer` |
| `CallbackOwnerResolver` | anything else — see below |
| `StanclOwnerResolver` | `stancl/tenancy` |
| `SpatieOwnerResolver` | `spatie/laravel-multitenancy` |

The last two are guarded by `class_exists` and degrade to platform-level when the package is absent, so they are safe to reference either way.

For resolution logic none of them express — a public site that resolves by HTTP host, say — register a callback in a service provider:

```php
use Enadstack\Blogify\Facades\Blogify;

Blogify::resolveOwnerUsing(fn () => app()->bound('currentTenant')
    ? app('currentTenant')
    : $this->tenantFromHost(request()->getHost()));
```

The callback lives on the class rather than in the config file **because a closure in config breaks `php artisan config:cache`**.

### Reading across owners

In `shared` mode every query is scoped to the resolved owner, strictly — a tenant's blog shows only their own posts, never the platform's. Escape hatches are explicit:

```php
Post::query()->get();               // the current owner only
Post::query()->forOwner($tenant);   // one specific owner
Post::query()->platform();          // platform-level content
Post::query()->ownedByAnyone();     // every tenant, excluding the platform
Post::query()->allOwners();         // everything — admin and moderation views
```

Anything running outside a request — a queued job, a scheduled command — has no owner context, so **it must use `allOwners()`** or it will silently see only platform rows. `blogify:publish-scheduled` already does.

---

## Content

### Posts and translations

Locale-independent facts live on the post; everything else lives in a translation row.

```php
$post = Post::create(['type' => 'post', 'status' => 'draft']);

$post->setTranslations([
    'en' => ['title' => 'Ten Tips for Better Sleep', 'body' => '...'],
    'ar' => ['title' => 'عشر نصائح لنوم أفضل', 'body' => '...'],
]);

$post->t('title', 'ar');   // 'عشر نصائح لنوم أفضل'
$post->slug('ar');         // 'عشر-نصائح-لنوم-أفضل'
```

Translations are **rows, not JSON columns**. A slug in a JSON column cannot be uniquely indexed and cannot be looked up through an index; one row per locale gives each language its own indexed slug, its own meta fields, and its own publish flag — so the Arabic can go live while the English is still being written.

### Slugs keep their script

`Str::slug` forces ASCII, which mangles Arabic into unreadable transliteration and drops Hebrew and CJK entirely:

```
Str::slug('مرحبا بالعالم')  => 'mrhba-balaaalm'   mangled
Str::slug('שלום עולם')      => ''                 dropped
```

Blogify preserves Unicode letters instead, while still folding accents on Latin input:

```
Slugger::make('مرحبا بالعالم')  => 'مرحبا-بالعالم'
Slugger::make('Café Münster')   => 'cafe-munster'
```

Slugs are unique per owner and locale, so two tenants can both publish `about-us`.

### Taxonomy

One `blogify_terms` table serves every taxonomy, discriminated by a `taxonomy` column. Adding one is a config change, not a migration.

```php
$category = Term::create(['taxonomy' => 'category']);
$category->setTranslation('ar', ['name' => 'قانون الأسرة']);

$post->terms()->attach($category);
```

Terms are owned like posts are, so the platform can define shared categories (`owner_key = '*'`) while each tenant adds their own.

### Media

A lean `blogify_media` table behind a `MediaAdapter` contract.

- `NativeMediaAdapter` (default) — writes to a Laravel disk. No dependencies.
- `SpatieMediaAdapter` — delegates to `spatie/laravel-medialibrary` if you already have it, reusing your conversions.

```php
$media = app(MediaAdapter::class)->store($request->file('image'), [
    'attachable' => $post,
    'collection' => 'hero',
    'alt' => ['en' => 'A clinic room', 'ar' => 'غرفة في العيادة'],
]);
```

Alt text and captions are JSON rather than translation rows — unlike slugs they are never uniquely indexed or looked up, so a separate table would buy nothing.

---

## SEO

Everything returns arrays. Rendering is yours.

```php
$url = fn (string $locale, string $slug) => route('blog.show', [$locale, $slug]);

Blogify::seo($post, 'ar', $url);      // title, description, canonical, robots, OG, Twitter, hreflang
Blogify::schema($post, 'ar', $url);   // JSON-LD, @type from the post's schema_type column
Blogify::sitemap($owner, $url);       // a Generator of sitemap entries
```

hreflang alternates come straight from the sibling translation rows — the payoff from storing translations as rows.

### Renaming a slug does not have to 404

Every retired slug is recorded, so the old URL can redirect instead of breaking every inbound link:

```php
if ($post = Blogify::resolveHistoricalSlug($slug, $locale)) {
    return redirect()->route('blog.show', [$locale, $post->slug($locale)], 301);
}
```

### Finding a post

```php
Blogify::findBySlug($slug, $locale);   // published, scoped to the current owner
```

A single-table indexed lookup with no join, because `owner_key` is denormalised onto the translations table.

---

## Configuration worth knowing about

### `database.key_type` — decide before you migrate

```php
'database' => ['key_type' => 'id'],   // id | ulid | uuid
```

Blogify's own tables can be keyed with bigints, ULIDs or UUIDs, to match the rest of your schema. **This is read by the migrations**, so it has to be set before `php artisan migrate` and cannot be changed afterwards without a manual migration. `blogify:install` asks.

Owner and author columns are always `string(40)` regardless, so a ULID-keyed tenant and a bigint-keyed user can both own content in the same table.

### `require_owner`

```php
'tenancy' => ['require_owner' => true],
```

In `shared` mode, throws rather than silently writing platform content when the resolver returns null. Useful for catching a missing middleware early. Leave it off if the platform also publishes.

### Everything else

Table names, model classes, locales, RTL languages, post types, taxonomies, reading speed and the sitemap chunk size are all configurable. The published config explains each one.

---

## Commands

| Command | Purpose |
|---|---|
| `blogify:install` | Publish and configure `config/blogify.php`. Fully scriptable via options. |
| `blogify:publish-scheduled` | Promote scheduled posts whose date has arrived. Auto-scheduled via `blogify.schedule.publish_cron`. |
| `blogify:sitemap` | Write a sitemap for platform-level content. |

Per-tenant sitemaps need your URL scheme, which the package cannot know — build those from `Blogify::sitemap($owner, $urlBuilder)`.

## Events

`PostPublished`, `PostUnpublished`, `PostDeleted` (with `$forced` distinguishing a soft delete), `TermCreated`.

---

## Routing note

Blogify registers no routes. If you add your own, mind the ordering — a catch-all like this will swallow `/blog`:

```php
Route::get('/{slug?}', [PageController::class, 'show'])->where('slug', '[a-z0-9-]+');
```

Register your blog routes **before** any such group.

## Publish tags

`blogify-config`, `blogify-migrations`, `blogify-migrations-tenant`, `blogify-translations`

## Requirements

PHP 8.2+, Laravel 11/12/13.

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan
```

## License

MIT.
