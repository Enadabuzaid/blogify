# CLAUDE.md

> Instructions for Claude (and Claude Code) when working on **enadstack/blogify**.
>
> Read this file in full before making any change. Read `AGENTS.md` for the
> workflow contract and the hard rules.

---

## Mental model

Five layers. Keep concerns where they belong.

```
Public API   →  Blogify facade, BelongsToBlogOwner / HasBlogTranslations / HasBlogSlug traits
Tenancy      →  OwnerResolver contract + six resolvers, OwnerScope, OwnerKey
Persistence  →  Eloquent models + migrations, BlogifySchema
Presentation →  SeoBuilder, SchemaBuilder, SitemapBuilder — arrays out, never markup
Integration  →  MediaAdapter, events, console commands
```

A change that crosses two layers is usually a smell. Ask whether the layers need
a new contract instead.

## The five decisions that shape everything

Understand these before changing anything. Each looks arbitrary and is not.

### 1. The owner is a nullable polymorphic reference

`NULL` = platform-level content. Anything else owns its own blog. One schema
serves a single site, a platform blog, and per-tenant blogs — and a single-tenant
install can become multi-tenant with **no new migration**, which is why the owner
columns exist even in `single` mode.

It is polymorphic rather than a flat `tenant_id` because the owning model differs
per application, and can differ *within* one application: a doctor, a clinic, and
a staff user may all own content.

### 2. Morph key columns are strings, never `morphs()`

`$table->morphs()` and `nullableMorphs()` emit an `unsignedBigInteger` id.
Blogify references models it does not own, and those are not all bigint-keyed — a
tenant may have a 26-character ULID while the user who wrote the post has a
bigint. A numeric column cannot hold both.

Always use `BlogifySchema::ownerColumns()` or `BlogifySchema::stringMorphs()`.
`tests/Feature/SharedTenancyTest.php` and `tests/Ulid/` are the regression tests.

### 3. `owner_key` exists because NULL breaks unique indexes

MySQL and PostgreSQL treat NULL as distinct from every other NULL inside a unique
index. `unique(['owner_type', 'owner_id', 'slug'])` therefore does **not** stop two
platform posts (both NULL/NULL) sharing a slug.

Every ownable table carries a non-null `owner_key` — `'*'` for the platform,
`morphClass:key` otherwise. Uniqueness then behaves identically on MySQL,
PostgreSQL and SQLite, and scoping reads one indexed column instead of two.

It is denormalised onto the translations tables too, for two reasons: a unique
index cannot span tables, and slug lookups become a single-table indexed hit with
no join. The cost is that an owner change has to be propagated — `Post::booted()`
does that.

### 4. Translations are rows, not JSON columns

A slug in a JSON column cannot be uniquely indexed and cannot be looked up
through an index. One row per locale gives each language its own indexed slug,
its own meta fields, and its own publish flag — so Arabic can go live while
English is still being written, and hreflang alternates come straight from
sibling rows.

Media alt text and captions **are** JSON, deliberately: unlike slugs they are
never uniquely indexed or looked up, so a table would add joins and buy nothing.

### 5. `Str::slug` cannot be used

It forces ASCII. Arabic becomes unreadable transliteration
(`'مرحبا بالعالم'` → `'mrhba-balaaalm'`); Hebrew and CJK vanish entirely, so every
such post would collide on `''`. `Support\Slugger` preserves Unicode letters,
while still routing Latin input through `Str::slug` so accents fold.

## Non-negotiables

1. **Every file:** `<?php`, blank line, `declare(strict_types=1);`.
2. **Every public method has typed parameters and a return type.** No `mixed` unless genuinely necessary.
3. **No business logic in models.** Relations, casts, scopes and trivial accessors only.
4. **Tenant scoping happens in one place: `OwnerScope`.** Never read `owner_key` directly to filter.
5. **All extension points are interfaces in `Contracts/`.** An `if ($x instanceof Y)` check means you should have added a contract method.
6. **Resolvers are stateless.** They are bound as singletons for the request; never cache request state on the instance.
7. **The SEO layer returns arrays.** It never renders markup, and it never assumes a URL scheme — the caller passes a `$urlBuilder`.
8. **Optional integrations never hard-depend.** `class_exists` guard, graceful degradation, `suggest` entry.
9. **Model hook ordering is explicit.** Trait boot order is not a contract. `HasBlogSlug` calls `prepareSlugScope()` before generating a slug for exactly this reason — a slug de-duplicated against an unset `owner_key` passes in PHP and then violates the index.
10. **`created` and `updated` hooks are separate.** `wasRecentlyCreated` stays true for the instance's whole lifetime, so a `saved` hook consulting it re-fires on every later save.

## Coding standards

- PSR-12 with Laravel conventions, enforced by Pint.
- Imports sorted, `Enadstack\` first, then `Illuminate\`, then global.
- Constructor property promotion for value objects and simple services.
- Backed enums for any closed set of strings. Never string constants.
- Table names and model classes always via config, never hardcoded.

## Test discipline

- Pest. `tests/Feature` for end-to-end, `tests/Unit` for single-class behaviour.
- Use the fixtures: `TestTenant` (ULID), `TestUser` (bigint), `TestGallery` (HasMedia). The mismatched key types are the point.
- Switch tenancy with `$this->actingForOwner($owner)` from `TestCase`.
- **Tenancy changes must be tested in all three modes.** **Schema changes must be tested under both key types** — add to `tests/Ulid/`.
- When asserting a database constraint, insert through the query builder so the model's own guards do not mask it. See `SingleTenancyTest`.

## Common tasks

### Add an owner resolver
1. Implement `Contracts\OwnerResolver` in `src/Resolvers/Owners/`.
2. Return a `?Model`, never an id — the morph class is needed too.
3. If it wraps a third-party package: `class_exists` guard, static `isAvailable()`, degrade to null.
4. Document it in `config/blogify.php` and the README table, and offer it in `InstallCommand::resolveResolver()`.
5. Test it in `tests/Feature/OwnerResolverTest.php`.

### Add a field to posts
1. Decide whether it is locale-independent. If it varies by language it belongs on `PostTranslation`.
2. New migration — never edit a released one.
3. Add the cast and the `@property` annotation. Use `Casts\UnicodeJson` for JSON that may hold Arabic.
4. Surface it in `SeoBuilder` / `SchemaBuilder` if it is SEO-relevant.

### Add a media adapter
1. Implement `Contracts\MediaAdapter`, or extend `NativeMediaAdapter` for a partial override.
2. Keep `blogify_media` as the index whatever the backend — the rest of the package reads it and must not care which adapter is active. That includes column semantics: `file_name` is the human-readable original name under every adapter.
3. `class_exists` guard plus a `guardAvailable()` that explains the misconfiguration.

### Fix a bug
Write the failing test first, make the smallest change, keep the suite green, add a `CHANGELOG.md` entry.

## What NOT to do

- ❌ Do not add a UI dependency. Filament, Inertia, Livewire — companion packages.
- ❌ Do not couple to a tenancy package. The scope reads `OwnerResolver` only.
- ❌ Do not add a `posts` or `categories` table without the `blogify_` prefix. Host apps already have those names.
- ❌ Do not put a closure in the config file.
- ❌ Do not assume a URL scheme. Subdomain, custom domain, path prefix — take a `$urlBuilder`.
- ❌ Do not suppress a PHPStan error with `@phpstan-ignore`, a baseline entry, an inline `@var`, or a cast. Fix the cause; each one so far has been a real bug.

## When in doubt

- **Read the contract first.** If it does not exist, you may be adding an extension point — design it deliberately.
- **Mirror a similar feature** already in the codebase.
- **Prefer a new small file** to extending an existing one.
- **Ask before adding a dependency.** The package's value comes from being lean.
