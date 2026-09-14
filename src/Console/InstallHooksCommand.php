<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;

/**
 * Writes a managed block into the repository's git hooks so version.json is
 * regenerated after every commit, merge, checkout, and rebase. The block is
 * fenced by markers, so re-running upgrades it in place and other hook
 * content is left alone.
 */
class InstallHooksCommand extends Command
{
    public const string BEGIN_MARKER = '# >>> laravel-app-version (managed block, re-run app:version:install-hooks to update)';

    public const string END_MARKER = '# <<< laravel-app-version';

    private const string SHEBANG = '#!/usr/bin/env sh';

    protected $signature = 'app:version:install-hooks
        {--flat : Refresh the committed version-info.json snapshot after source changes}
        {--uninstall : Remove the managed block from every hook}';

    protected $description = 'Install git hooks that regenerate version.json after commits, merges, checkouts, and rebases';

    public function handle(): int
    {
        $hooksDirectory = $this->hooksDirectory();

        if ($hooksDirectory === null) {
            // A deploy artifact without .git has nothing to hook; composer
            // scripts call this on every install, so that is not an error.
            $this->warn('Not a git repository, no hooks installed: '.AppVersion::repositoryPath());

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($hooksDirectory);

        $rows = [];

        foreach ((array) config('app-version.hooks', []) as $hook) {
            $path = $hooksDirectory.DIRECTORY_SEPARATOR.$hook;
            $rows[] = [$hook, $this->option('uninstall') ? $this->uninstall($path) : $this->install($path)];
        }

        $this->table(['Hook', 'Result'], $rows);
        $this->line("Hooks directory: {$hooksDirectory}");

        return self::SUCCESS;
    }

    /**
     * Hooks inherit the PATH of whatever committed (an IDE, a GUI client, an
     * agent shell), which often lacks php. Prefer php from PATH but fall back
     * to the interpreter that ran the installer, baked in as an absolute path.
     */
    public static function managedBlock(?string $phpBinary = null, bool $flat = false): string
    {
        $fallback = str_replace('\\', '/', $phpBinary ?? PHP_BINARY);

        return implode("\n", [
            self::BEGIN_MARKER,
            'if [ -f artisan ]; then',
            '    APP_VERSION_PHP="$(command -v php 2>/dev/null || printf \'%s\' \''.$fallback.'\')"',
            '    "$APP_VERSION_PHP" artisan app:version'.($flat ? ' --flat' : '').' --no-interaction --quiet >/dev/null 2>&1'
                .($flat ? ' || printf "%s\n" "Version snapshot refresh failed; run php artisan app:version --flat before release." >&2' : ' || true'),
            'fi',
            self::END_MARKER,
        ]);
    }

    protected function install(string $path): string
    {
        $block = self::managedBlock(flat: $this->option('flat') || config('app-version.flat', false));

        if (! File::exists($path)) {
            File::put($path, self::SHEBANG."\n\n".$block."\n");
            $this->makeExecutable($path);

            return 'created';
        }

        $contents = (string) File::get($path);

        if ($this->hasManagedBlock($contents)) {
            File::put($path, $this->replaceManagedBlock($contents, $block));
            $this->makeExecutable($path);

            return 'updated';
        }

        File::put($path, rtrim($contents)."\n\n".$block."\n");
        $this->makeExecutable($path);

        return 'appended';
    }

    protected function uninstall(string $path): string
    {
        if (! File::exists($path)) {
            return 'absent';
        }

        $contents = (string) File::get($path);

        if (! $this->hasManagedBlock($contents)) {
            return 'untouched';
        }

        $remaining = trim($this->replaceManagedBlock($contents, ''));

        if ($remaining === '' || $remaining === self::SHEBANG) {
            File::delete($path);

            return 'removed';
        }

        File::put($path, $remaining."\n");

        return 'block removed';
    }

    protected function hasManagedBlock(string $contents): bool
    {
        return str_contains($contents, self::BEGIN_MARKER) && str_contains($contents, self::END_MARKER);
    }

    protected function replaceManagedBlock(string $contents, string $replacement): string
    {
        $pattern = '/[ \t]*'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'[ \t]*\n?/s';

        return (string) preg_replace($pattern, $replacement === '' ? '' : $replacement."\n", $contents, 1);
    }

    protected function makeExecutable(string $path): void
    {
        @chmod($path, 0755);
    }

    /**
     * `git rev-parse --git-path hooks` honors core.hooksPath and worktrees;
     * fall back to the conventional directory when git is unavailable.
     */
    protected function hooksDirectory(): ?string
    {
        $repository = AppVersion::repositoryPath();
        $result = Process::path($repository)->run('git rev-parse --git-path hooks');

        if ($result->successful() && trim($result->output()) !== '') {
            $path = trim($result->output());

            return $this->isAbsolutePath($path)
                ? $path
                : rtrim($repository, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        $conventional = rtrim($repository, '/\\').DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'hooks';

        return File::isDirectory(dirname($conventional)) ? $conventional : null;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
