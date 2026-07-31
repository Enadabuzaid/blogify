# AGENTS.md

> Operating manual for any AI coding agent (Claude Code, Cursor, Codex, Aider, etc.)
> working on **enadstack/blogify**.
>
> If you only read one file, read this one.

---

## Project at a glance

- **Package:** `enadstack/blogify`
- **Type:** Laravel package, MIT, open-source.
- **Stack:** PHP 8.2+, Laravel 11/12/13.
- **Tests:** Pest, Orchestra Testbench, SQLite in-memory.
- **Static analysis:** Larastan / PHPStan, level 5, must be clean.
- **Formatting:** Pint.
- **Status:** v1.0.0 — the public API is stable and follows semver.

## What this package is

A headless content engine: articles, taxonomy, media and SEO for both
platform-level and per-tenant content, with first-class RTL/Arabic support.

**No UI ships in core.** No views, no routes, no controllers, no admin panel.
Framework integrations belong in companion packages.

## Workflow contract for agents

1. **Read.** Open the files named in the task. Read `CLAUDE.md` if you have not this session.
2. **Plan.** State in 3–5 bullets what you will do and which files you will touch. Stop and confirm if the plan crosses architectural layers (see CLAUDE.md "Mental model").
3. **Test first when fixing bugs.** Add a failing test before changing source.
4. **Implement.** The smallest change that satisfies the test.
5. **Verify.** `composer check` — format, analyse, test. All three must pass.
6. **Document.** Update `CHANGELOG.md` under `[Unreleased]`. Update `README.md` if the public API changed.
7. **Summarize.** End with: files changed, tests added, anything skipped, anything risky.

## Hard rules

- **Never edit a published migration.** Add a new one.
- **Never use `$table->morphs()` or `nullableMorphs()`** for an owner, author or attachable. They emit an `unsignedBigInteger` id, and this package has to reference models it does not own — some of which are ULID-keyed. Use `BlogifySchema::stringMorphs()` / `ownerColumns()`.
- **Never put a closure in `config/blogify.php`.** It breaks `php artisan config:cache`. Runtime callbacks go on the `Blogify` class.
- **Never add a hard dependency.** The `require` block is `illuminate/*` only, deliberately. Optional integrations go behind a contract with a `class_exists` guard and a `suggest` entry.
- **Never break the public API in a minor version.** The public API is: the `Blogify` facade, `BelongsToBlogOwner`, `HasBlogTranslations`, `HasBlogSlug`, every contract in `src/Contracts/`, every event constructor, and the model relations and scopes.
- **Never scope reads by reading `owner_key` directly.** Scoping happens in `OwnerScope`, one place.
- **Never write a query in a command or job without `allOwners()`** unless it genuinely should see only platform rows. Outside a request there is no owner context, so the global scope silently narrows to the platform.
- **Never call `exit()` in a command.** Return a status. `exit()` takes down the whole process, including the test runner.
- **Never write tests that need internet access.**
- **Never commit** `vendor/`, `composer.lock` (this is a library), `.phpunit.cache`, `build/`.

## Soft rules

- **Prefer composition.** New behaviour usually means a new class implementing a contract.
- **Prefer a config value to a migration.** Post types and taxonomies are plain strings for exactly this reason.
- **Use Pest's expectations API**, not `$this->assert*`.
- **One concept per file.**
- **Comment the why, never the what.** Several decisions here look arbitrary until you know the reason — the `owner_key` sentinel, string morph columns, the custom slugger. Those reasons are load-bearing; keep them written down.

## Repository layout

```
.
├── config/blogify.php          User-facing config (publishable, heavily commented)
├── database/migrations/        7 migrations, do not edit after release
├── lang/{en,ar}/blogify.php
├── src/
│   ├── BlogifyServiceProvider.php
│   ├── Blogify.php             Facade-backing class
│   ├── Casts/                  UnicodeJson
│   ├── Concerns/               BelongsToBlogOwner, HasBlogTranslations, HasBlogSlug, HasBlogifyKey
│   ├── Console/                install, publish-scheduled, sitemap
│   ├── Contracts/              OwnerResolver, MediaAdapter — all extension points
│   ├── Enums/                  PostStatus, ContentFormat
│   ├── Events/
│   ├── Exceptions/
│   ├── Facades/
│   ├── Media/                  Native and Spatie adapters
│   ├── Models/                 Eloquent models, lean; Scopes/OwnerScope
│   ├── Resolvers/Owners/       Six owner resolvers
│   ├── Seo/                    SeoBuilder, SchemaBuilder, SitemapBuilder
│   └── Support/                OwnerKey, Slugger, Locales, ReadingTime, Schema/BlogifySchema
└── tests/
    ├── Feature/                End-to-end behaviour
    ├── Fixtures/               TestTenant (ULID), TestUser (bigint), TestGallery (HasMedia)
    ├── Ulid/                   The same behaviour with key_type=ulid
    ├── Unit/
    ├── Pest.php
    ├── TestCase.php
    └── UlidTestCase.php
```

## Common commands

```bash
composer install
composer check                              # format + analyse + test
vendor/bin/pest tests/Feature/SharedTenancyTest.php
vendor/bin/pest --filter="hides platform posts"
vendor/bin/phpstan analyse
```

## Definition of "done"

- ✅ Code runs, `composer check` is green.
- ✅ New behaviour has a feature or unit test; bug fixes have a regression test.
- ✅ Tenancy changes are tested in **all three modes** (`single`, `shared`, `isolated`).
- ✅ Schema changes are tested under **both** `key_type=id` and `key_type=ulid`.
- ✅ `CHANGELOG.md` updated under `[Unreleased]`; `README.md` updated if the public API changed.
- ✅ No commented-out code, no `dd()` / `dump()` / `var_dump()`.
- ✅ Commit message follows Conventional Commits.

## How to think about scope

This package is small on purpose. Before adding a feature, ask:

1. **Core or companion package?** UI, framework integrations and opinionated workflows belong in a companion.
2. **Could this be an extension point instead?** If yes, build the extension point.
3. **Does this make the package harder to learn?** Every public method is documentation debt.

When unsure, choose the smaller change.

## Communication norms

- **Surface ambiguity early.** If a task reads two ways, ask before coding.
- **Flag risk explicitly.** "I changed X and it could affect Y" beats a clean diff.
- **Be honest about what you did not test.** "I could not run the suite because…" is fine. Pretending you ran it is not.
- **Suggest follow-ups** rather than silently fixing things outside the task.
