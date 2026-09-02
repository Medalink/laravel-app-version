<?php

use Illuminate\Support\Facades\File;
use Medalink\AppVersion\AppVersion;

it('reads version metadata from version.json', function (): void {
    $this->writeVersionJson('3.1.4', ['build' => 42, 'full' => '3.1.4.42+abc1234']);

    expect(AppVersion::version())->toBe('3.1.4')
        ->and(AppVersion::build())->toBe(42)
        ->and(AppVersion::commit())->toBe('abc1234')
        ->and(AppVersion::full())->toBe('3.1.4.42+abc1234');
});

it('falls back to the VERSION file when version.json is missing', function (): void {
    $this->writeVersionFile('0.4.0');

    expect(AppVersion::data())->toBe([
        'version' => '0.4.0',
        'build' => 0,
        'commit' => AppVersion::DEV_COMMIT,
        'full' => '0.4.0.0+dev',
    ]);
});

it('reports 0.0.0 when neither source exists', function (): void {
    expect(AppVersion::version())->toBe('0.0.0');
});

it('zero-fills stats missing from older version.json files', function (): void {
    $this->writeVersionJson('1.0.0');

    expect(AppVersion::stats())->toBe([
        'total_commits' => 0,
        'commit_additions' => 0,
        'commit_deletions' => 0,
        'build_additions' => 0,
        'build_deletions' => 0,
        'lifetime_additions' => 0,
        'lifetime_deletions' => 0,
    ]);
});

it('exposes a client payload with stats', function (): void {
    $this->writeVersionJson('1.0.0', ['stats' => ['total_commits' => 500, 'build_additions' => 100]]);

    $payload = AppVersion::toArray();

    expect($payload)->toHaveKeys(['version', 'build', 'commit', 'full', 'stats'])
        ->and($payload['stats']['total_commits'])->toBe(500)
        ->and($payload['stats']['build_additions'])->toBe(100)
        ->and($payload['stats']['lifetime_deletions'])->toBe(0);
});

it('caches the file read for the process until cleared', function (): void {
    $this->writeVersionJson('1.0.0');
    $first = AppVersion::data();

    File::put(AppVersion::jsonPath(), json_encode(['version' => '9.9.9', 'build' => 9, 'commit' => 'x', 'full' => '9.9.9.9+x']));

    expect(AppVersion::data())->toBe($first);

    AppVersion::clearCache();

    expect(AppVersion::version())->toBe('9.9.9');
});

it('resolves the VERSION file path relative to the repository root', function (): void {
    expect(AppVersion::versionFileRelativePath())->toBe('VERSION');

    config()->set('app-version.version_file', $this->workspace.'/config/VERSION');

    expect(AppVersion::versionFileRelativePath())->toBe('config/VERSION');
});
