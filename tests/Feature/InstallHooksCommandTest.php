<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\Console\InstallHooksCommand;

beforeEach(function (): void {
    $this->hooks = $this->workspace.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'hooks';
    File::ensureDirectoryExists($this->hooks);

    Process::fake([
        'git rev-parse --git-path hooks' => Process::result(output: ".git/hooks\n"),
    ]);
});

it('creates every configured hook with the managed block', function (): void {
    $this->artisan('app:version:install-hooks')->assertSuccessful();

    foreach (['post-commit', 'post-merge', 'post-checkout', 'post-rewrite'] as $hook) {
        $contents = File::get($this->hooks.DIRECTORY_SEPARATOR.$hook);

        expect($contents)->toStartWith('#!/usr/bin/env sh')
            ->and($contents)->toContain(InstallHooksCommand::BEGIN_MARKER)
            ->and($contents)->toContain('artisan app:version ${APP_VERSION_GIT_DIR:+"--stats-cache=$APP_VERSION_GIT_DIR/app-version-stats.json"} --no-interaction --quiet')
            ->and($contents)->toContain('git rev-parse --path-format=absolute --git-common-dir')
            ->and($contents)->toContain('command -v php')
            ->and($contents)->toContain(str_replace('\\', '/', PHP_BINARY))
            ->and($contents)->toContain(InstallHooksCommand::END_MARKER);
    }
});

it('appends to an existing hook and upgrades its block on re-run without duplicating it', function (): void {
    $path = $this->hooks.DIRECTORY_SEPARATOR.'post-commit';
    File::put($path, "#!/bin/sh\necho custom\n");

    $this->artisan('app:version:install-hooks')->assertSuccessful();
    $this->artisan('app:version:install-hooks')->assertSuccessful();

    $contents = File::get($path);

    expect($contents)->toContain('echo custom')
        ->and(substr_count($contents, InstallHooksCommand::BEGIN_MARKER))->toBe(1)
        ->and(substr_count($contents, InstallHooksCommand::END_MARKER))->toBe(1);
});

it('removes only the managed block on uninstall and deletes hooks it fully owns', function (): void {
    $custom = $this->hooks.DIRECTORY_SEPARATOR.'post-commit';
    File::put($custom, "#!/bin/sh\necho custom\n");

    $this->artisan('app:version:install-hooks')->assertSuccessful();
    $this->artisan('app:version:install-hooks', ['--uninstall' => true])->assertSuccessful();

    expect(File::get($custom))->toBe("#!/bin/sh\necho custom\n")
        ->and(File::exists($this->hooks.DIRECTORY_SEPARATOR.'post-merge'))->toBeFalse();
});

it('honours a custom hooks path reported by git', function (): void {
    $custom = $this->workspace.DIRECTORY_SEPARATOR.'githooks';
    Process::fake(['git rev-parse --git-path hooks' => Process::result(output: $custom."\n")]);

    $this->artisan('app:version:install-hooks')->assertSuccessful();

    expect(File::exists($custom.DIRECTORY_SEPARATOR.'post-commit'))->toBeTrue();
});

it('upgrades existing hooks to refresh the committed flat file', function (): void {
    $this->artisan('app:version:install-hooks')->assertSuccessful();
    $this->artisan('app:version:install-hooks', ['--flat' => true])->assertSuccessful();
    expect(File::get($this->hooks.DIRECTORY_SEPARATOR.'post-commit'))->toContain('artisan app:version --flat ${APP_VERSION_GIT_DIR:+');
});

it('is a warned no-op outside a git repository so composer scripts stay safe', function (): void {
    File::deleteDirectory($this->workspace.DIRECTORY_SEPARATOR.'.git');
    Process::fake(['git rev-parse --git-path hooks' => Process::result(exitCode: 128)]);

    $this->artisan('app:version:install-hooks')
        ->expectsOutputToContain('Not a git repository')
        ->assertSuccessful();

    expect(File::isDirectory($this->workspace.DIRECTORY_SEPARATOR.'.git'))->toBeFalse();
});

it('runs every worktree of a repository against one statistics cache in the common git directory', function (): void {
    $sh = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where sh 2>NUL' : 'command -v sh'));

    if ($sh === '') {
        $this->markTestSkipped('No POSIX sh to run the hook block.');
    }

    $sh = strtok($sh, "\r\n");
    $root = str_replace('\\', '/', $this->workspace);
    $repository = "{$root}/main";
    $linked = "{$root}/linked";
    $bin = "{$root}/bin";
    $calls = "{$root}/calls";
    File::ensureDirectoryExists($repository);
    File::ensureDirectoryExists($bin);
    File::put("{$bin}/php", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$calls}'\n");
    chmod("{$bin}/php", 0755);

    $git = fn (string $directory, string $arguments) => Process::path($directory)->run("git -c user.name=t -c user.email=t@example.test {$arguments}")->throw();
    $git($repository, 'init -q');
    $git($repository, 'commit -q --allow-empty -m first');
    $git($repository, 'worktree add -q ../linked -b other');
    File::put("{$repository}/artisan", '');
    File::put("{$linked}/artisan", '');

    $block = InstallHooksCommand::managedBlock();

    foreach ([$repository, $linked] as $directory) {
        $result = Process::path($directory)->env(['PATH' => $bin.PATH_SEPARATOR.getenv('PATH')])->run([$sh, '-c', $block]);
        expect($result->successful())->toBeTrue($result->errorOutput());
    }

    $common = trim(Process::path($repository)->run('git rev-parse --path-format=absolute --git-common-dir')->output());
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', (string) File::get($calls)))));

    expect($lines)->toBe([
        "artisan app:version --stats-cache={$common}/app-version-stats.json --no-interaction --quiet",
        "artisan app:version --stats-cache={$common}/app-version-stats.json --no-interaction --quiet",
    ]);
});
