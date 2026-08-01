<?php

declare(strict_types=1);

use Enadstack\Blogify\Media\NativeMediaAdapter;
use Enadstack\Blogify\Resolvers\Owners\AuthOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\CallbackOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\ContainerOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;
use Illuminate\Support\Facades\File;

afterEach(function () {
    if (File::exists(config_path('blogify.php'))) {
        File::delete(config_path('blogify.php'));
    }
});

it('publishes and configures the config file', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en,ar',
    ])->assertSuccessful();

    expect(File::exists(config_path('blogify.php')))->toBeTrue();

    $contents = File::get(config_path('blogify.php'));

    expect($contents)->toContain("'mode' => 'single'")
        ->and($contents)->toContain("'key_type' => 'id'")
        ->and($contents)->toContain("'supported' => ['en', 'ar']");
});

/*
 * The published config's explanatory comments are most of its value, so the
 * command rewrites values in place rather than regenerating the file.
 */
it('keeps the explanatory comments intact', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'shared',
        '--key-type' => 'ulid',
        '--resolver' => ContainerOwnerResolver::class,
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'ar,en',
    ])->assertSuccessful();

    $contents = File::get(config_path('blogify.php'));

    expect($contents)->toContain('Database-per-tenant')
        ->and($contents)->toContain('platform-level content')
        ->and($contents)->toContain('read inside the migrations')
        ->and($contents)->toContain('|-----');
});

it('writes the chosen tenancy mode, key type and resolver', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'shared',
        '--key-type' => 'ulid',
        '--resolver' => ContainerOwnerResolver::class,
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'ar,en',
    ])->assertSuccessful();

    $contents = File::get(config_path('blogify.php'));

    expect($contents)->toContain("'mode' => 'shared'")
        ->and($contents)->toContain("'key_type' => 'ulid'")
        ->and($contents)->toContain("'resolver' => ContainerOwnerResolver::class")
        ->and($contents)->toContain('use '.ContainerOwnerResolver::class.';');
});

it('sets the first locale as default and fallback', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'ar,en',
    ])->assertSuccessful();

    $contents = File::get(config_path('blogify.php'));

    expect($contents)->toContain("'default' => 'ar'")
        ->and($contents)->toContain("'fallback' => 'ar'");
});

/*
 * Nothing to resolve when there is one blog, and a database-per-tenant setup is
 * already isolated by its connection — so neither mode should be handed a
 * resolver it does not need.
 */
it('forces the null resolver outside shared mode', function (string $mode) {
    $this->artisan('blogify:install', [
        '--mode' => $mode,
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en',
    ])->assertSuccessful();

    expect(File::get(config_path('blogify.php')))
        ->toContain("'resolver' => ".class_basename(NullOwnerResolver::class).'::class');
})->with(['single', 'isolated']);

it('produces a config file that still parses', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'shared',
        '--key-type' => 'ulid',
        '--resolver' => AuthOwnerResolver::class,
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'ar,en',
    ])->assertSuccessful();

    $config = require config_path('blogify.php');

    expect($config)->toBeArray()
        ->and($config['tenancy']['mode'])->toBe('shared')
        ->and($config['tenancy']['resolver'])->toBe(AuthOwnerResolver::class)
        ->and($config['database']['key_type'])->toBe('ulid')
        ->and($config['locales']['supported'])->toBe(['ar', 'en'])
        ->and($config['media']['adapter'])->toBe(NativeMediaAdapter::class);
});

/*
 * A closure anywhere in this file would break `php artisan config:cache`, which
 * is why the owner callback lives on the Blogify class instead.
 */
it('produces a config file that can be cached', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'shared',
        '--key-type' => 'id',
        '--resolver' => ContainerOwnerResolver::class,
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en,ar',
    ])->assertSuccessful();

    $config = require config_path('blogify.php');

    expect(fn () => serialize($config))->not->toThrow(Exception::class)
        ->and(var_export($config, true))->toBeString();
});

it('rejects an invalid mode', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'nonsense',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en',
    ])->assertFailed();
});

it('rejects an invalid key type', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'bigint',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en',
    ])->assertFailed();
});

it('leaves an existing config alone unless forced', function () {
    File::put(config_path('blogify.php'), "<?php return ['sentinel' => true];\n");

    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en',
    ])->assertSuccessful();

    expect(File::get(config_path('blogify.php')))->toContain('sentinel');
});

it('overwrites an existing config when forced', function () {
    File::put(config_path('blogify.php'), "<?php return ['sentinel' => true];\n");

    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'en',
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get(config_path('blogify.php')))->not->toContain('sentinel');
});

it('falls back to en when no locales are given', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'single',
        '--key-type' => 'id',
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => ' , ',
    ])->assertSuccessful();

    expect(require config_path('blogify.php'))
        ->toHaveKey('locales')
        ->and((require config_path('blogify.php'))['locales']['supported'])->toBe(['en']);
});

/*
 * The published config has to satisfy Pint's ordered_imports, or every
 * application that runs the installer inherits a lint failure it did not write.
 */
it('keeps the config imports alphabetically ordered', function () {
    $this->artisan('blogify:install', [
        '--mode' => 'shared',
        '--key-type' => 'id',
        '--resolver' => CallbackOwnerResolver::class,
        '--media-adapter' => NativeMediaAdapter::class,
        '--locales' => 'ar,en',
    ])->assertSuccessful();

    preg_match_all('/^use (.+);$/m', File::get(config_path('blogify.php')), $matches);

    $imports = $matches[1];
    $sorted = $imports;
    usort($sorted, 'strcasecmp');

    expect($imports)->toContain(CallbackOwnerResolver::class)
        ->and($imports)->toBe($sorted);
});
