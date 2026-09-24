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

it('round-trips commit sums and release payloads', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->useContext('ctx');
    $cache->remember(statsCacheSha(1), 10, 2, 3);
    $cache->rememberRelease('key', ['version' => '1.0.0']);
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->useContext('ctx');

    expect($loaded->sums(statsCacheSha(1)))->toBe(['additions' => 10, 'deletions' => 2, 'commits' => 3])
        ->and($loaded->release('key'))->toBe(['version' => '1.0.0']);
});

it('starts empty from a missing, unreadable or foreign file', function (?string $contents): void {
    $path = statsCacheFile();

    if ($contents !== null) {
        File::put($path, $contents);
    }

    $cache = StatsCache::load($path);
    $cache->useContext('');

    expect($cache->nearestAncestor([statsCacheSha(1) => 0]))->toBeNull()
        ->and($cache->release('key'))->toBeNull();
})->with([
    'missing' => [null],
    'not json' => ['{not json'],
    'another format' => [json_encode(['format' => 99, 'commits' => [statsCacheSha(1) => ['additions' => 1, 'deletions' => 1, 'commits' => 1]]])],
]);

it('skips malformed commit entries', function (): void {
    $path = statsCacheFile();
    File::put($path, json_encode(['format' => StatsCache::FORMAT, 'context' => 'ctx', 'commits' => [
        statsCacheSha(1) => ['additions' => 1, 'deletions' => 1, 'commits' => 1],
        'not-a-sha' => ['additions' => 1, 'deletions' => 1, 'commits' => 1],
        statsCacheSha(2) => ['additions' => '1', 'deletions' => 1, 'commits' => 1],
        statsCacheSha(3) => ['additions' => 1, 'deletions' => 1, 'commits' => 0],
    ]]));

    $cache = StatsCache::load($path);
    $cache->useContext('ctx');

    expect($cache->nearestAncestor([statsCacheSha(1) => 0, 'not-a-sha' => 0, statsCacheSha(2) => 0, statsCacheSha(3) => 0]))->toBe(statsCacheSha(1))
        ->and($cache->sums(statsCacheSha(2)))->toBeNull()
        ->and($cache->sums(statsCacheSha(3)))->toBeNull();
});

it('drops commit sums, but not releases, when the context changes', function (): void {
    $path = statsCacheFile();
    $cache = StatsCache::load($path);
    $cache->useContext('git 2.47');
    $cache->remember(statsCacheSha(1), 1, 1, 1);
    $cache->rememberRelease('key', ['version' => '1.0.0']);
    $cache->save();

    $loaded = StatsCache::load($path);
    $loaded->useContext('git 2.48');

    expect($loaded->sums(statsCacheSha(1)))->toBeNull()
        ->and($loaded->release('key'))->toBe(['version' => '1.0.0']);
});

it('picks the reachable cached commit with the most history', function (): void {
    $cache = StatsCache::load(statsCacheFile());
    $cache->useContext('');
    $cache->remember(statsCacheSha(1), 1, 1, 5);
    $cache->remember(statsCacheSha(2), 1, 1, 9);
    $cache->remember(statsCacheSha(3), 1, 1, 20);

    expect($cache->nearestAncestor([statsCacheSha(1) => 0, statsCacheSha(2) => 1]))->toBe(statsCacheSha(2))
        ->and($cache->nearestAncestor([statsCacheSha(4) => 0]))->toBeNull();
});

it('keeps the most recently used commit entries', function (): void {
    $path = statsCacheFile();
    $commits = [];

    foreach (range(1, StatsCache::MAX_COMMITS + 10) as $n) {
        $commits[statsCacheSha($n)] = ['additions' => $n, 'deletions' => 0, 'commits' => $n, 'used' => $n <= 10 ? 1 : 1000 + $n];
    }

    File::put($path, json_encode(['format' => StatsCache::FORMAT, 'context' => 'ctx', 'commits' => $commits]));
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
