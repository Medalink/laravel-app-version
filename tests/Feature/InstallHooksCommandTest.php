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
            ->and($contents)->toContain('artisan app:version --no-interaction --quiet')
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
    expect(File::get($this->hooks.DIRECTORY_SEPARATOR.'post-commit'))->toContain('artisan app:version --flat --no-interaction');
});

it('is a warned no-op outside a git repository so composer scripts stay safe', function (): void {
    File::deleteDirectory($this->workspace.DIRECTORY_SEPARATOR.'.git');
    Process::fake(['git rev-parse --git-path hooks' => Process::result(exitCode: 128)]);

    $this->artisan('app:version:install-hooks')
        ->expectsOutputToContain('Not a git repository')
        ->assertSuccessful();

    expect(File::isDirectory($this->workspace.DIRECTORY_SEPARATOR.'.git'))->toBeFalse();
});
