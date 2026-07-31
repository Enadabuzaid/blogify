<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Console;

use Enadstack\Blogify\Media\NativeMediaAdapter;
use Enadstack\Blogify\Media\SpatieMediaAdapter;
use Enadstack\Blogify\Resolvers\Owners\AuthOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\CallbackOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\ContainerOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\SpatieOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\StanclOwnerResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Interactive setup: publishes the config and answers the three questions that
 * are awkward to change later.
 *
 * Key type is the pressing one — it is read by the migrations, so it has to be
 * decided before `migrate` runs. Tenancy mode and the resolver can be changed
 * freely afterwards, because the owner columns exist in every mode.
 */
class InstallCommand extends Command
{
    protected $signature = 'blogify:install
        {--mode= : Tenancy mode: single, shared or isolated}
        {--key-type= : Primary key type for Blogify tables: id, ulid or uuid}
        {--resolver= : Fully-qualified owner resolver class}
        {--media-adapter= : Fully-qualified media adapter class}
        {--locales= : Comma-separated locales, e.g. en,ar}
        {--force : Overwrite an existing config file}';

    protected $description = 'Install Blogify: publish and configure config/blogify.php';

    public function handle(): int
    {
        $this->components->info('Installing Blogify');

        try {
            $mode = $this->resolveMode();
            $keyType = $this->resolveKeyType();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $resolver = $this->resolveResolver($mode);
        $locales = $this->resolveLocales();
        $mediaAdapter = $this->resolveMediaAdapter();

        $this->publishConfig();

        $written = $this->writeConfig([
            'mode' => $mode,
            'key_type' => $keyType,
            'resolver' => $resolver,
            'locales' => $locales,
            'media_adapter' => $mediaAdapter,
        ]);

        if (! $written) {
            $this->components->warn(
                'Could not rewrite config/blogify.php automatically. Set these by hand:'
            );
            $this->components->bulletList([
                "tenancy.mode => '{$mode}'",
                'tenancy.resolver => '.class_basename($resolver).'::class',
                "database.key_type => '{$keyType}'",
                'locales.supported => '.json_encode($locales),
            ]);
        }

        $this->summarise($mode, $keyType, $resolver, $locales);

        return self::SUCCESS;
    }

    protected function resolveMode(): string
    {
        $mode = $this->option('mode');

        if (is_string($mode) && $mode !== '') {
            return $this->validated($mode, ['single', 'shared', 'isolated'], 'mode');
        }

        return $this->choice(
            'How is content owned?',
            [
                'single' => 'Single site — one blog, no tenants',
                'shared' => 'Multi-tenant, one database — scope rows by owner',
                'isolated' => 'Multi-tenant, database per tenant',
            ],
            'single'
        );
    }

    protected function resolveKeyType(): string
    {
        $keyType = $this->option('key-type');

        if (is_string($keyType) && $keyType !== '') {
            return $this->validated($keyType, ['id', 'ulid', 'uuid'], 'key-type');
        }

        $this->components->info(
            'Primary key type for Blogify tables. This is read by the migrations, '
            .'so it cannot be changed once you have migrated.'
        );

        return $this->choice(
            'Which key type do your other tables use?',
            [
                'id' => 'Auto-incrementing bigint (Laravel default)',
                'ulid' => 'ULID',
                'uuid' => 'UUID',
            ],
            'id'
        );
    }

    protected function resolveResolver(string $mode): string
    {
        $resolver = $this->option('resolver');

        if (is_string($resolver) && $resolver !== '') {
            return $resolver;
        }

        // Nothing to resolve when there is one blog, and a database-per-tenant
        // setup is already isolated by its connection.
        if ($mode !== 'shared') {
            return NullOwnerResolver::class;
        }

        $options = [
            ContainerOwnerResolver::class => 'Container binding — a middleware binds the tenant (e.g. app()->instance(\'currentTenant\', $tenant))',
            AuthOwnerResolver::class => 'Authenticated user — read a relation such as $user->tenant',
            CallbackOwnerResolver::class => 'Custom callback — register Blogify::resolveOwnerUsing(...) in a provider',
        ];

        if (StanclOwnerResolver::isAvailable()) {
            $options[StanclOwnerResolver::class] = 'stancl/tenancy';
        }

        if (SpatieOwnerResolver::isAvailable()) {
            $options[SpatieOwnerResolver::class] = 'spatie/laravel-multitenancy';
        }

        return $this->choice('How should Blogify resolve the current owner?', $options, ContainerOwnerResolver::class);
    }

    /**
     * @return array<int, string>
     */
    protected function resolveLocales(): array
    {
        $locales = $this->option('locales');

        if (! is_string($locales) || $locales === '') {
            $locales = $this->ask('Which locales will you publish in? (comma separated)', 'en,ar');
        }

        $parsed = array_values(array_filter(array_map('trim', explode(',', (string) $locales))));

        return $parsed === [] ? ['en'] : $parsed;
    }

    protected function resolveMediaAdapter(): string
    {
        $adapter = $this->option('media-adapter');

        if (is_string($adapter) && $adapter !== '') {
            return $adapter;
        }

        if (! SpatieMediaAdapter::isAvailable()) {
            return NativeMediaAdapter::class;
        }

        return $this->confirm('spatie/laravel-medialibrary is installed. Use it for media?', true)
            ? SpatieMediaAdapter::class
            : NativeMediaAdapter::class;
    }

    protected function publishConfig(): void
    {
        $exists = File::exists(config_path('blogify.php'));

        if ($exists && ! $this->option('force')) {
            $this->components->twoColumnDetail('config/blogify.php', '<fg=yellow>already exists</>');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'blogify-config',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('config/blogify.php', '<fg=green>published</>');
    }

    /**
     * Rewrite the published config in place.
     *
     * Targeted replacements rather than a regenerated file, so the explanatory
     * comments in the shipped config survive — they are most of its value.
     *
     * @param  array{mode: string, key_type: string, resolver: string, locales: array<int, string>, media_adapter: string}  $values
     */
    protected function writeConfig(array $values): bool
    {
        $path = config_path('blogify.php');

        if (! File::exists($path)) {
            return false;
        }

        $contents = (string) File::get($path);
        $original = $contents;

        $contents = $this->replaceValue($contents, 'mode', "env('BLOGIFY_TENANCY_MODE', 'single')", "'{$values['mode']}'");
        $contents = $this->replaceValue($contents, 'key_type', "env('BLOGIFY_KEY_TYPE', 'id')", "'{$values['key_type']}'");
        $contents = $this->replaceValue($contents, 'resolver', 'NullOwnerResolver::class', $this->classReference($values['resolver']));
        $contents = $this->replaceValue($contents, 'adapter', 'NativeMediaAdapter::class', $this->classReference($values['media_adapter']));
        $contents = $this->replaceValue($contents, 'supported', "['en', 'ar']", $this->arrayLiteral($values['locales']));
        $contents = $this->replaceValue(
            $contents,
            'default',
            "env('BLOGIFY_DEFAULT_LOCALE', 'en')",
            "'".($values['locales'][0] ?? 'en')."'"
        );
        $contents = $this->replaceValue(
            $contents,
            'fallback',
            "env('BLOGIFY_FALLBACK_LOCALE', 'en')",
            "'".($values['locales'][0] ?? 'en')."'"
        );

        // A resolver or adapter outside the shipped namespaces needs its own
        // import; the config's existing `use` lines only cover the defaults.
        $contents = $this->ensureImport($contents, $values['resolver']);
        $contents = $this->ensureImport($contents, $values['media_adapter']);

        if ($contents === $original) {
            return true;
        }

        File::put($path, $contents);

        return true;
    }

    /**
     * Replace `'key' => <search>` with `'key' => <replacement>`.
     */
    protected function replaceValue(string $contents, string $key, string $search, string $replacement): string
    {
        if ($search === $replacement) {
            return $contents;
        }

        $pattern = '/(\''.preg_quote($key, '/').'\'\s*=>\s*)'.preg_quote($search, '/').'/';

        return preg_replace($pattern, '$1'.str_replace('$', '\\$', $replacement), $contents, 1) ?? $contents;
    }

    /**
     * The shortest reference that will resolve inside the config file.
     */
    protected function classReference(string $class): string
    {
        return class_basename($class).'::class';
    }

    /**
     * Add a `use` statement for a class the shipped config does not import.
     */
    protected function ensureImport(string $contents, string $class): string
    {
        $import = 'use '.ltrim($class, '\\').';';

        if (str_contains($contents, $import)) {
            return $contents;
        }

        // Insert after the last existing import so the block stays together.
        if (preg_match_all('/^use .+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $contents;
        }

        $last = end($matches[0]);

        if ($last === false) {
            return $contents;
        }

        $insertAt = $last[1] + strlen($last[0]);

        return substr($contents, 0, $insertAt)."\n".$import.substr($contents, $insertAt);
    }

    /**
     * @param  array<int, string>  $values
     */
    protected function arrayLiteral(array $values): string
    {
        return '['.implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values)).']';
    }

    /**
     * Check an option value against its allowed set.
     *
     * Throws rather than exiting: exit() inside a command takes down the whole
     * process, which among other things makes the command untestable.
     *
     * @param  array<int, string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    protected function validated(string $value, array $allowed, string $option): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid --{$option} [{$value}]. Expected one of: ".implode(', ', $allowed).'.'
            );
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $locales
     */
    protected function summarise(string $mode, string $keyType, string $resolver, array $locales): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Tenancy mode', $mode);
        $this->components->twoColumnDetail('Key type', $keyType);
        $this->components->twoColumnDetail('Owner resolver', class_basename($resolver));
        $this->components->twoColumnDetail('Locales', implode(', ', $locales));
        $this->newLine();

        $next = ['php artisan migrate'];

        if ($mode === 'isolated') {
            $next = [
                'php artisan vendor:publish --tag=blogify-migrations-tenant',
                'migrate your tenant databases',
            ];
        }

        if ($resolver === CallbackOwnerResolver::class) {
            $next[] = 'register Blogify::resolveOwnerUsing(...) in a service provider';
        }

        if ($resolver === ContainerOwnerResolver::class) {
            $next[] = "bind the current tenant: app()->instance('currentTenant', \$tenant)";
        }

        if ($resolver === AuthOwnerResolver::class) {
            $next[] = 'check blogify.tenancy.user_relation matches your user model';
        }

        $this->components->info('Next:');
        $this->components->bulletList($next);
    }
}
