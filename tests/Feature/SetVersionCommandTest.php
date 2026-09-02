<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;

beforeEach(function (): void {
    Process::fake([
        'git add *' => Process::result(),
        'git commit *' => Process::result(),
        'git tag --points-at HEAD*' => Process::result(output: 'v9.8.7'),
        'git tag *' => Process::result(),
        'git describe *' => Process::result(output: 'v9.8.6'),
        'git rev-parse *' => Process::result(output: 'abc1234'),
        'git rev-list *' => Process::result(output: '0'),
        'git log *' => Process::result(output: ''),
    ]);

    File::put($this->workspace.'/.env', "APP_NAME=Sample\nAPP_VERSION=0.0.1\n");
    File::put($this->workspace.'/.env.example', "APP_NAME=Sample\nAPP_VERSION=0.0.1\n");
});

it('rejects malformed versions', function (): void {
    $this->artisan('app:version:set', ['version' => 'nine'])->assertFailed();
});

it('writes the VERSION file and APP_VERSION in env files', function (): void {
    $this->artisan('app:version:set', ['version' => '9.8.7'])->assertSuccessful();

    expect(trim((string) File::get(AppVersion::versionFile())))->toBe('9.8.7')
        ->and(File::get($this->workspace.'/.env'))->toContain('APP_VERSION=9.8.7')
        ->and(File::get($this->workspace.'/.env.example'))->toContain('APP_VERSION=9.8.7');
});

it('commits the bump, stages only tracked files, and tags it', function (): void {
    $this->artisan('app:version:set', ['version' => '9.8.7'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'git add ')
        && str_contains($process->command, 'VERSION')
        && str_contains($process->command, '.env.example')
        && ! preg_match('/git add .*["\']\.env["\']/', $process->command));
    Process::assertRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'git commit -m ')
        && str_contains($process->command, 'Bump version to 9.8.7'));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'git tag v9.8.7');
});

it('skips committing with --no-commit and tagging with --no-tag', function (): void {
    $this->artisan('app:version:set', ['version' => '9.8.7', '--no-commit' => true])->assertSuccessful();

    Process::assertNotRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'git commit'));

    $this->artisan('app:version:set', ['version' => '9.8.8', '--no-tag' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'git commit'));
    Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === 'git tag v9.8.8');
});

it('regenerates version.json after setting', function (): void {
    $this->artisan('app:version:set', ['version' => '9.8.7'])->assertSuccessful();

    expect(File::exists(AppVersion::jsonPath()))->toBeTrue()
        ->and(AppVersion::version())->toBe('9.8.7')
        ->and(AppVersion::build())->toBe(0);
});
