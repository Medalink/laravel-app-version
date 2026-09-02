<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Support\SemanticVersion;

class SetVersionCommand extends Command
{
    protected $signature = 'app:version:set
        {version : The semantic version to set (e.g. 2.0.1)}
        {--no-commit : Write the files without committing or tagging}
        {--no-tag : Commit the bump without creating the semver tag}';

    protected $description = 'Set the application version, commit and tag the bump, and regenerate version metadata';

    public function handle(): int
    {
        $version = (string) $this->argument('version');

        if (! SemanticVersion::isValid($version)) {
            $this->error("Invalid version format: '{$version}' (expected: X.Y.Z)");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname(AppVersion::versionFile()));
        File::put(AppVersion::versionFile(), $version."\n");
        $this->info('Updated '.AppVersion::versionFileRelativePath()." to {$version}");

        $stagedFiles = [AppVersion::versionFileRelativePath()];

        foreach ((array) config('app-version.env_files', []) as $envFile) {
            if ($this->updateEnvFile((string) $envFile, $version) && $envFile !== '.env') {
                $stagedFiles[] = (string) $envFile;
            }
        }

        $this->newLine();

        if ((bool) config('app-version.commit_on_set', true) && ! $this->option('no-commit')) {
            $this->commitVersionBump($version, $stagedFiles);
        }

        $this->newLine();
        $this->info('Regenerating version metadata...');

        return $this->call('app:version');
    }

    /**
     * @param  list<string>  $files
     */
    protected function commitVersionBump(string $version, array $files): void
    {
        $repository = AppVersion::repositoryPath();

        Process::path($repository)->run('git add '.implode(' ', array_map(escapeshellarg(...), $files)));

        $result = Process::path($repository)->run('git commit -m '.escapeshellarg("chore: Bump version to {$version}"));

        if (! $result->successful()) {
            $this->warn('Git commit failed; you may need to commit manually.');
            $this->line($result->errorOutput());

            return;
        }

        $this->info('Committed version bump to git');

        if (! (bool) config('app-version.tag_on_set', true) || $this->option('no-tag')) {
            return;
        }

        $tag = SemanticVersion::tag($version);
        $tagResult = Process::path($repository)->run("git tag {$tag}");

        if (! $tagResult->successful()) {
            $this->warn("Failed to create tag {$tag}; it may already exist.");

            return;
        }

        $this->info("Tagged as {$tag}");
    }

    protected function updateEnvFile(string $relativePath, string $version): bool
    {
        $path = rtrim(AppVersion::repositoryPath(), '/\\').DIRECTORY_SEPARATOR.$relativePath;

        if (! File::exists($path)) {
            return false;
        }

        $contents = (string) File::get($path);

        if (! preg_match('/^APP_VERSION=.*/m', $contents)) {
            $this->line("APP_VERSION not found in {$relativePath}, skipping");

            return false;
        }

        File::put($path, (string) preg_replace('/^APP_VERSION=.*/m', "APP_VERSION={$version}", $contents));
        $this->info("Updated APP_VERSION in {$relativePath}");

        return true;
    }
}
