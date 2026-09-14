<?php

namespace Medalink\AppVersion\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\AppVersionServiceProvider;
use Medalink\AppVersion\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** A throwaway directory standing in for the host repository. */
    protected string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'app-version-'.Str::lower(Str::random(12));
        mkdir($this->workspace, 0777, true);

        parent::setUp();

        AppVersion::clearCache();
    }

    protected function tearDown(): void
    {
        AppVersion::clearCache();
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AppVersionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Sample');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app-version.repository_path', $this->workspace);
        $app['config']->set('app-version.version_file', $this->workspace.DIRECTORY_SEPARATOR.'VERSION');
        $app['config']->set('app-version.json_path', $this->workspace.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'version.json');
        $app['config']->set('app-version.flat', false);
        $app['config']->set('app-version.flat_path', $this->workspace.DIRECTORY_SEPARATOR.'version-info.json');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function writeVersionFile(string $version): void
    {
        File::put(AppVersion::versionFile(), $version."\n");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function writeVersionJson(string $version, array $overrides = []): void
    {
        File::ensureDirectoryExists(dirname(AppVersion::jsonPath()));
        File::put(AppVersion::jsonPath(), json_encode(array_merge([
            'version' => $version,
            'build' => 1,
            'commit' => 'abc1234',
            'full' => "{$version}.1+abc1234",
        ], $overrides)));
        AppVersion::clearCache();
    }
}
