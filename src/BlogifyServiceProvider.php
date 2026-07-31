<?php

declare(strict_types=1);

namespace Enadstack\Blogify;

use Enadstack\Blogify\Console\InstallCommand;
use Enadstack\Blogify\Console\PublishScheduledCommand;
use Enadstack\Blogify\Console\SitemapCommand;
use Enadstack\Blogify\Contracts\MediaAdapter;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Media\NativeMediaAdapter;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class BlogifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blogify.php', 'blogify');

        // The owner resolver is a singleton so owner context stays consistent
        // for the whole request — two reads in the same request must not
        // disagree about who the content belongs to.
        $this->app->singleton(OwnerResolver::class, function ($app) {
            /** @var class-string $class */
            $class = config('blogify.tenancy.resolver', NullOwnerResolver::class);

            return $app->make($class);
        });

        $this->app->singleton(MediaAdapter::class, function ($app) {
            /** @var class-string $class */
            $class = config('blogify.media.adapter', NativeMediaAdapter::class);

            return $app->make($class);
        });

        $this->app->singleton(Blogify::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'blogify');

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                PublishScheduledCommand::class,
                SitemapCommand::class,
            ]);

            $this->registerSchedule();
        }
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/blogify.php' => config_path('blogify.php'),
        ], 'blogify-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'blogify-migrations');

        // Database-per-tenant setups keep tenant tables in their own migration
        // path, so they get their own tag rather than having to move files.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations/tenant'),
        ], 'blogify-migrations-tenant');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/blogify'),
        ], 'blogify-translations');
    }

    /**
     * Register blogify:publish-scheduled with the application's scheduler.
     *
     * Deferred via booted() because the Schedule instance is not available while
     * providers are still booting.
     */
    protected function registerSchedule(): void
    {
        $cron = config('blogify.schedule.publish_cron');

        if (! is_string($cron) || $cron === '') {
            return;
        }

        $this->app->booted(function () use ($cron): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('blogify:publish-scheduled')->cron($cron);
        });
    }
}
