# Changelog

All notable changes to `enadstack/blogify` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-08-01

### Fixed

- `blogify:install` appended new `use` statements to the published config instead of inserting them in alphabetical order, leaving every application that ran the installer with a Pint `ordered_imports` failure it had not written.

## [1.0.0] - 2026-07-31

### Added

- Initial release.
- Nullable polymorphic content owner: `NULL` is platform-level, anything else owns its own blog.
- Three tenancy modes — `single`, `shared` (one database, global scope per owner) and `isolated` (database per tenant).
- Six owner resolvers behind the `OwnerResolver` contract: `Null`, `Container`, `Auth`, `Callback`, `Stancl` and `Spatie`. No tenancy package is a dependency; the third-party ones are `class_exists`-guarded.
- `owner_key` sentinel column, so unique indexes involving the owner behave identically on MySQL, PostgreSQL and SQLite.
- Configurable primary key type (`id`, `ulid`, `uuid`) read by the migrations. Owner and author columns are always `string(40)`, so a ULID-keyed tenant and a bigint-keyed user can own content in the same table.
- Posts with per-locale translation rows: indexed slug, meta fields and publish flag per language.
- Unicode-preserving slug generator, with accent folding retained for Latin input.
- Unified `blogify_terms` taxonomy table with a `taxonomy` discriminator and optional hierarchy.
- Media behind a `MediaAdapter` contract, with a native disk implementation and an optional `spatie/laravel-medialibrary` adapter.
- `UnicodeJson` cast so Arabic is stored as characters rather than `\uXXXX` escapes.
- SEO: `SeoBuilder` (meta, Open Graph, Twitter, hreflang), `SchemaBuilder` (JSON-LD) and `SitemapBuilder` (streaming, per owner).
- Slug history, so renaming a slug can 301 instead of 404.
- Commands: `blogify:install`, `blogify:publish-scheduled`, `blogify:sitemap`.
- Events: `PostPublished`, `PostUnpublished`, `PostDeleted`, `TermCreated`.
- Arabic and English translation files.
- Owner scoping stays correct outside a request context: parent lookups and pivot relations bypass the global scope, so commands, queued jobs and scheduler runs read and write the right owner's rows.
