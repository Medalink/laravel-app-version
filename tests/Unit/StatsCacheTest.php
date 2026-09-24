<?php

use Illuminate\Support\Facades\File;
use Medalink\AppVersion\Support\StatsCache;

function statsCacheFile(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'app-version-stats-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
}

function statsCacheSha(int $n): string
{
    return str_pad(dechex($n), 40, '0', STR_PAD_LEFT);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'app-version-stats-'.getmypid().'-*') ?: [] as $file) {
        File::delete($file);
    }
});

it('round-trips commit sums, first-commit dates, VERSION listings and release payloads', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->useContext('ctx');
    $cache->remember(statsCacheSha(1), 10, 2, 3, '2024-01-01T00:00:00+00:00', 'VERSION', [statsCacheSha(1), statsCacheSha(9)]);
    $cache->rememberVersionContents('VERSION', [statsCacheSha(1) => '1.1.0', statsCacheSha(9) => null, statsCacheSha(8) => '0.9.0']);
    $cache->rememberRelease('key', ['version' => '1.0.0']);
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->useContext('ctx');

    expect($loaded->sums(statsCacheSha(1)))->toBe(['additions' => 10, 'deletions' => 2, 'commits' => 3])
        ->and($loaded->firstCommitAt(statsCacheSha(1)))->toBe('2024-01-01T00:00:00+00:00')
        ->and($loaded->versionLog(statsCacheSha(1), 'VERSION'))->toBe([statsCacheSha(1), statsCacheSha(9)])
        ->and($loaded->versionLog(statsCacheSha(1), 'config/VERSION'))->toBeNull()
        // Contents are kept for the commits a kept listing names.
        ->and($loaded->versionContents('VERSION', [statsCacheSha(1), statsCacheSha(9), statsCacheSha(8)]))->toBe([statsCacheSha(1) => '1.1.0', statsCacheSha(9) => null])
        ->and($loaded->release('key'))->toBe(['version' => '1.0.0']);
});

it('keeps what a boundary entry does not know when it is remembered again', function (): void {
    $cache = StatsCache::load(statsCacheFile());
    $cache->useContext('ctx');
    $cache->remember(statsCacheSha(1), 10, 2, 3, '2024-01-01T00:00:00+00:00', 'VERSION', [statsCacheSha(1)]);
    $cache->remember(statsCacheSha(1), 10, 2, 3);

    expect($cache->firstCommitAt(statsCacheSha(1)))->toBe('2024-01-01T00:00:00+00:00')
        ->and($cache->versionLog(statsCacheSha(1), 'VERSION'))->toBe([statsCacheSha(1)]);
});

it('starts empty from a missing, unreadable or foreign file', function (?string $contents): void {
    $path = statsCacheFile();

    if ($contents !== null) {
        File::put($path, $contents);
    }

    $cache = StatsCache::load($path);
    $cache->useContext('');

    expect($cache->candidates())->toBe([])
        ->and($cache->sums(statsCacheSha(1)))->toBeNull()
        ->and($cache->release('key'))->toBeNull();
})->with([
    'missing' => [null],
    'not json' => ['{not json'],
    'another format' => [json_encode(['format' => 99, 'commits' => [statsCacheSha(1) => ['additions' => 1, 'deletions' => 1, 'commits' => 1]]])],
]);

it('skips malformed commit entries and fields', function (): void {
    $path = statsCacheFile();
    File::put($path, json_encode(['format' => StatsCache::FORMAT, 'context' => 'ctx', 'directories' => [], 'directory_attributes' => 'attrs', 'commits' => [
        statsCacheSha(1) => ['additions' => 1, 'deletions' => 1, 'commits' => 1, 'first_commit_at' => '2024-01-01T00:00:00+00:00'],
        'not-a-sha' => ['additions' => 1, 'deletions' => 1, 'commits' => 1, 'first_commit_at' => '2024-01-01T00:00:00+00:00'],
        statsCacheSha(2) => ['additions' => '1', 'deletions' => 1, 'commits' => 1],
        statsCacheSha(3) => ['additions' => 1, 'deletions' => 1, 'commits' => 0],
        statsCacheSha(4) => ['additions' => 1, 'deletions' => 1, 'commits' => 1, 'first_commit_at' => 5, 'version_log' => ['path' => 'VERSION', 'commits' => ['nope']]],
    ]]));

    $cache = StatsCache::load($path);
    $cache->useContext('ctx');

    expect($cache->candidates())->toBe([statsCacheSha(1)])
        ->and($cache->sums(statsCacheSha(2)))->toBeNull()
        ->and($cache->sums(statsCacheSha(3)))->toBeNull()
        ->and($cache->sums(statsCacheSha(4)))->toBe(['additions' => 1, 'deletions' => 1, 'commits' => 1])
        ->and($cache->firstCommitAt(statsCacheSha(4)))->toBeNull()
        ->and($cache->versionLog(statsCacheSha(4), 'VERSION'))->toBeNull();
});

it('drops commit entries and VERSION contents, but not releases, when the context changes', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->useContext('git 2.47');
    $cache->remember(statsCacheSha(1), 1, 1, 1, '2024-01-01T00:00:00+00:00', 'VERSION', [statsCacheSha(1)]);
    $cache->rememberVersionContents('VERSION', [statsCacheSha(1) => '1.0.0']);
    $cache->rememberRelease('key', ['version' => '1.0.0']);
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->useContext('git 2.48');

    expect($loaded->sums(statsCacheSha(1)))->toBeNull()
        ->and($loaded->versionContents('VERSION', [statsCacheSha(1)]))->toBe([])
        ->and($loaded->release('key'))->toBe(['version' => '1.0.0']);
});

it('offers the entries written for a run HEAD, the most history first', function (): void {
    $cache = StatsCache::load(statsCacheFile());
    $cache->useContext('');
    $cache->remember(statsCacheSha(1), 1, 1, 5, '2024-01-01T00:00:00+00:00');
    $cache->remember(statsCacheSha(2), 1, 1, 9, '2024-01-01T00:00:00+00:00');
    // A range boundary: sums only.
    $cache->remember(statsCacheSha(3), 1, 1, 20);

    expect($cache->candidates())->toBe([statsCacheSha(2), statsCacheSha(1)]);
});

it('forgets the commit entries it is given', function (): void {
    $cache = StatsCache::load(statsCacheFile());
    $cache->useContext('');
    $cache->remember(statsCacheSha(1), 1, 1, 5, '2024-01-01T00:00:00+00:00');
    $cache->remember(statsCacheSha(2), 1, 1, 9, '2024-01-01T00:00:00+00:00');
    $cache->remember(statsCacheSha(3), 1, 1, 20);
    $cache->forget([statsCacheSha(2), statsCacheSha(3), statsCacheSha(4)]);

    expect($cache->commitHashes())->toBe([statsCacheSha(1)])
        ->and($cache->candidates())->toBe([statsCacheSha(1)]);
});

it('keeps the directories and their attributes fingerprint with the commit entries', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->useContext('ctx');
    $cache->remember(statsCacheSha(1), 1, 1, 1, '2024-01-01T00:00:00+00:00');
    $cache->rememberDirectories(['app', 'app/Http'], 'attrs');
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->useContext('ctx');

    expect($loaded->directories())->toBe(['app', 'app/Http'])
        ->and($loaded->directoryAttributes())->toBe('attrs')
        ->and($loaded->candidates())->toBe([statsCacheSha(1)]);

    $loaded->forgetCommits();

    expect($loaded->directories())->toBe([])
        ->and($loaded->directoryAttributes())->toBe('')
        ->and($loaded->candidates())->toBe([]);

    $other = StatsCache::load($path);
    $other->useContext('another context');

    expect($other->directories())->toBe([])
        ->and($other->candidates())->toBe([]);
});

it('drops commit entries a file keeps without the directories they were checked against', function (): void {
    $path = statsCacheFile();
    File::put($path, json_encode(['format' => StatsCache::FORMAT, 'context' => 'ctx', 'commits' => [
        statsCacheSha(1) => ['additions' => 1, 'deletions' => 1, 'commits' => 1, 'first_commit_at' => '2024-01-01T00:00:00+00:00'],
    ]]));

    $cache = StatsCache::load($path);
    $cache->useContext('ctx');

    expect($cache->candidates())->toBe([])
        ->and($cache->sums(statsCacheSha(1)))->toBeNull();
});

it('keeps the most recently used commit entries', function (): void {
    $path = statsCacheFile();
    $commits = [];

    foreach (range(1, StatsCache::MAX_COMMITS + 10) as $n) {
        $commits[statsCacheSha($n)] = ['additions' => $n, 'deletions' => 0, 'commits' => $n, 'used' => $n <= 10 ? 1 : 1000 + $n];
    }

    File::put($path, json_encode(['format' => StatsCache::FORMAT, 'context' => 'ctx', 'directories' => [], 'directory_attributes' => 'attrs', 'commits' => $commits]));
    $cache = StatsCache::load($path);
    $cache->useContext('ctx');
    $cache->sums(statsCacheSha(1));
    $cache->save();

    $kept = array_keys(json_decode(File::get($path), true)['commits']);

    // The entry just read, then the newest MAX - 1 by last use.
    expect($kept)->toHaveCount(StatsCache::MAX_COMMITS)
        ->toContain(statsCacheSha(1), statsCacheSha(12), statsCacheSha(StatsCache::MAX_COMMITS + 10))
        ->not->toContain(statsCacheSha(2), statsCacheSha(10), statsCacheSha(11));
});

it('forgets releases a flat run did not use', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->rememberRelease('old', ['version' => '0.9.0']);
    $cache->rememberRelease('kept', ['version' => '1.0.0']);
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->release('kept');
    $loaded->forgetUnusedReleases();

    expect($loaded->release('old'))->toBeNull()
        ->and($loaded->release('kept'))->toBe(['version' => '1.0.0']);
});
