<?php

namespace Medalink\AppVersion;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Medalink\AppVersion\Console\BackfillReleaseNotesCommand;
use Medalink\AppVersion\Console\GenerateVersionCommand;
use Medalink\AppVersion\Console\InstallHooksCommand;
use Medalink\AppVersion\Console\PublishReleaseNotesCommand;
use Medalink\AppVersion\Console\ResetReleaseNoteReadsCommand;
use Medalink\AppVersion\Console\SetVersionCommand;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Git\GitReleaseCommitSource;

class AppVersionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/app-version.php', 'app-version');

        $this->app->singleton(ReleaseCommitSource::class, GitReleaseCommitSource::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/app-version.php' => config_path('app-version.php'),
        ], 'app-version-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'app-version-migrations');

        if ((bool) config('app-version.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateVersionCommand::class,
                SetVersionCommand::class,
                InstallHooksCommand::class,
                PublishReleaseNotesCommand::class,
                BackfillReleaseNotesCommand::class,
                ResetReleaseNoteReadsCommand::class,
            ]);
        }

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('App Version', static fn (): array => [
                'Version' => AppVersion::full(),
                'Build' => (string) AppVersion::build(),
                'Commit' => AppVersion::commit(),
            ]);
        }
    }
}
