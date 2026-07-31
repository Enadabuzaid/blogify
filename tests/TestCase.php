<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Tests;

use Enadstack\Blogify\Blogify;
use Enadstack\Blogify\BlogifyServiceProvider;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;
use Enadstack\Blogify\Tests\Fixtures\TestTenant;
use Enadstack\Blogify\Tests\Fixtures\TestUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFixtureSchema();

        // The owner callback is static, so it survives between tests unless it
        // is cleared explicitly.
        Blogify::forgetOwnerCallback();
    }

    protected function getPackageProviders($app): array
    {
        return [
            // medialibrary is a dev dependency only. Booting it here is what lets
            // SpatieMediaAdapter be genuinely exercised rather than only asserted
            // to be absent.
            MediaLibraryServiceProvider::class,
            BlogifyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('blogify.tenancy.mode', 'single');
        $app['config']->set('blogify.tenancy.resolver', NullOwnerResolver::class);
        $app['config']->set('blogify.database.key_type', $this->keyType());
        $app['config']->set('blogify.locales.supported', ['en', 'ar']);
        $app['config']->set('blogify.locales.default', 'en');
        $app['config']->set('blogify.locales.fallback', 'en');

        // Short morph aliases, so owner_key stays readable in assertions and
        // reflects what a real application with a morph map would produce.
        $app['config']->set('blogify.schedule.publish_cron', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate')->run();

        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:rollback')->run();
        });
    }

    /**
     * The primary key type Blogify tables are built with for this test.
     *
     * Overridden by the key-type matrix tests, which run the same assertions
     * against 'id' and 'ulid' to prove a bigint-keyed owner and a ULID-keyed
     * owner can share a table.
     */
    protected function keyType(): string
    {
        return 'id';
    }

    /**
     * Tables standing in for an application's own models.
     *
     * Deliberately mismatched key types: TestTenant is ULID-keyed like a real
     * tenant model tends to be, TestUser is a plain bigint. Both must be able to
     * own content in the same blogify_posts table.
     */
    protected function setUpFixtureSchema(): void
    {
        if (! Schema::hasTable('test_tenants')) {
            Schema::create('test_tenants', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('test_users')) {
            Schema::create('test_users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->ulid('test_tenant_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('test_galleries')) {
            Schema::create('test_galleries', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        $this->setUpMediaLibrarySchema();
    }

    /**
     * medialibrary's own table, built by hand.
     *
     * Its migration ships as a publishable stub, so creating the table directly
     * avoids coupling the suite to that publishing step and to the stub's exact
     * filename across versions.
     */
    protected function setUpMediaLibrarySchema(): void
    {
        if (Schema::hasTable('media')) {
            return;
        }

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    /**
     * Switch to shared-DB tenancy with a fixed owner for the rest of the test.
     */
    protected function actingForOwner(?object $owner): void
    {
        config()->set('blogify.tenancy.mode', 'shared');

        $this->app->instance(OwnerResolver::class, new class($owner) implements OwnerResolver
        {
            public function __construct(private readonly ?object $owner) {}

            public function resolve(): ?Model
            {
                return $this->owner instanceof Model ? $this->owner : null;
            }

            public function hasOwner(): bool
            {
                return $this->resolve() !== null;
            }
        });
    }

    protected function makeTenant(string $name = 'Acme'): TestTenant
    {
        return TestTenant::query()->create(['name' => $name]);
    }

    protected function makeUser(string $name = 'Author'): TestUser
    {
        return TestUser::query()->create(['name' => $name]);
    }
}
