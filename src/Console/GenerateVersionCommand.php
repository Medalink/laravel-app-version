<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesCompiler;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesPublisher;
use Medalink\AppVersion\Support\SemanticVersion;
use Medalink\AppVersion\Support\StatsCache;
use ReflectionClass;
use RuntimeException;
use Throwable;

class GenerateVersionCommand extends Command
{
    protected $signature = 'app:version
        {--flat : Write version-info.json with statistics and full release history for committing}
        {--strict : Fail when Git metadata or diff statistics cannot be read}
        {--stats-cache= : Keep cumulative diff statistics (and, with --flat, earlier releases) in this file so only new history is read}
        {--output= : Write the metadata to this file instead of the configured path}';

    protected $description = 'Generate version.json from the VERSION file, semver git tags, and commit statistics';

    public function handle(): int
    {
        $flat = $this->option('flat') || config('app-version.flat', false);
        $output = $this->pathOption('output');
        $statsCachePath = $this->pathOption('stats-cache');

        if ($output === false || $statsCachePath === false) {
            $this->error('--output and --stats-cache require a path.');

            return self::FAILURE;
        }

        // An explicit --output always gets a file; the shortcut only protects
        // the committed snapshot from rewriting itself.
        if ($flat && $output === null && $this->snapshotOnlyCommit()) {
            $this->info('The last commit only records the existing release snapshot; keeping its source metadata.');

            return self::SUCCESS;
        }

        $cache = $statsCachePath !== null ? StatsCache::load($statsCachePath) : null;

        $versionFile = AppVersion::versionFile();

        if (! File::exists($versionFile)) {
            $this->error("VERSION file not found at: {$versionFile}");

            return self::FAILURE;
        }

        $fallbackVersion = trim((string) File::get($versionFile));

        if (! SemanticVersion::isValid($fallbackVersion)) {
            $this->error("Invalid version format in VERSION file: '{$fallbackVersion}' (expected: X.Y.Z)");

            return self::FAILURE;
        }

        $commit = $this->runTrimmed('git rev-parse --short HEAD') ?? 'unknown';
        $release = $this->resolveRelease($fallbackVersion);
        $build = $release['exact_tag'] || $release['build_range'] === null
            ? 0
            : (int) ($this->runTrimmed("git rev-list --count {$release['build_range']}") ?? '0');
        $version = $release['version'];
        $full = "{$version}.{$build}+{$commit}";

        $data = [
            'version' => $version,
            'build' => $build,
            'commit' => $commit,
            'full' => $full,
            'committed_at' => $this->runTrimmed('git log -1 --format=%cI HEAD'),
            'first_commit_at' => $this->firstCommitAt(),
            'stats' => $cache !== null
                ? $this->gatherCachedStats($release['stats_range'], $cache)
                : $this->gatherStats($release['stats_range']),
        ];

        if ($flat) {
            $data['source_commit'] = $this->runTrimmed('git rev-parse HEAD');
            $data['release_notes'] = AppVersion::usingData($data, function () use ($full, $version, $data, $cache): array {
                $publisher = app(ReleaseNotesPublisher::class);
                $history = $publisher->versionHistory();
                $fingerprint = $cache !== null ? $this->releaseFingerprint($publisher) : null;
                $releases = [];

                foreach (array_reverse($history, true) as $index => $entry) {
                    // Only earlier versions are fixed by their bounding commits;
                    // the running one ends at HEAD and is always compiled.
                    $key = $fingerprint !== null && $entry['version'] !== $version
                        ? $this->releaseCacheKey($fingerprint, $entry, $history[$index + 1] ?? null)
                        : null;
                    $payload = $key !== null ? $cache?->release($key) : null;

                    if ($payload === null) {
                        $payload = $publisher->payloadForVersion($entry['version'], strict: true, useStoredReleases: false);

                        if ($entry['version'] === $version && $data['committed_at'] !== null) {
                            $payload['published_at'] = $data['committed_at'];
                        }

                        if ($key !== null) {
                            // Store what the file will contain (dates as their JSON strings).
                            $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                            $cache?->rememberRelease($key, $payload);
                        }
                    }

                    $releases[] = $payload;
                }

                if ($releases === []) {
                    throw new RuntimeException('No release history could be exported.');
                }

                return ['format' => 1, 'build' => $full, 'releases' => $releases];
            });
            $cache?->forgetUnusedReleases();
        }

        $outputPath = $output ?? ($flat ? AppVersion::flatPath() : AppVersion::jsonPath());
        File::ensureDirectoryExists(dirname($outputPath));
        File::replace($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        AppVersion::clearCache();

        if ($cache !== null) {
            try {
                $cache->save();
            } catch (Throwable $e) {
                $this->warn("Could not update the stats cache at {$cache->path()}: {$e->getMessage()}");
            }
        }

        $this->info("Version: {$full}");
        $this->table(
            ['Field', 'Value'],
            collect($data)->except(['stats', 'release_notes'])->map(static fn ($value, $key): array => [$key, (string) ($value ?? '-')])->values()->all(),
        );

        $stats = $data['stats'];
        $this->table(['Stat', 'Value'], [
            ['Last commit additions', number_format($stats['commit_additions'])],
            ['Last commit deletions', number_format($stats['commit_deletions'])],
            ['Build additions', number_format($stats['build_additions'])],
            ['Build deletions', number_format($stats['build_deletions'])],
            ['Lifetime additions', number_format($stats['lifetime_additions'])],
            ['Lifetime deletions', number_format($stats['lifetime_deletions'])],
            ['Total commits', number_format($stats['total_commits'])],
        ]);

        return self::SUCCESS;
    }

    /**
     * Semver git tags win over the VERSION file so a release tag can drive
     * production metadata before VERSION is bumped. A tag on HEAD is build 0;
     * otherwise builds count from the newest reachable tag, unless VERSION is
     * ahead of it (a bump not yet tagged), in which case builds count from the
     * commit that last touched VERSION. With no tag and VERSION never
     * committed there is no boundary at all: build 0 and empty build stats,
     * rather than counting the whole history as one build.
     *
     * @return array{version: string, build_range: string|null, stats_range: string|null, exact_tag: bool}
     */
    protected function resolveRelease(string $fallbackVersion): array
    {
        $versionFileCommit = $this->runTrimmed('git log --format=%H -1 -- '.AppVersion::versionFileRelativePath());
        $versionFileRange = $versionFileCommit !== null ? "{$versionFileCommit}..HEAD" : null;
        $exactTag = $this->exactSemverTagAtHead();

        if ($exactTag !== null) {
            $previousTag = $this->previousSemverTag();

            return [
                'version' => SemanticVersion::fromTag($exactTag),
                'build_range' => "{$exactTag}..HEAD",
                'stats_range' => ($previousTag ?? $exactTag).'..HEAD',
                'exact_tag' => true,
            ];
        }

        $latestTag = $this->latestReachableSemverTag();

        if ($latestTag === null) {
            return [
                'version' => $fallbackVersion,
                'build_range' => $versionFileRange,
                'stats_range' => $versionFileRange,
                'exact_tag' => false,
            ];
        }

        $tagVersion = SemanticVersion::fromTag($latestTag);

        if (SemanticVersion::compare($fallbackVersion, $tagVersion) > 0) {
            return [
                'version' => $fallbackVersion,
                'build_range' => $versionFileRange,
                'stats_range' => $versionFileRange,
                'exact_tag' => false,
            ];
        }

        return [
            'version' => $tagVersion,
            'build_range' => "{$latestTag}..HEAD",
            'stats_range' => "{$latestTag}..HEAD",
            'exact_tag' => false,
        ];
    }

    protected function exactSemverTagAtHead(): ?string
    {
        $output = $this->runTrimmed('git tag --points-at HEAD --sort=-v:refname --list "'.SemanticVersion::tagGlob().'"');

        return $output === null ? null : SemanticVersion::firstTagIn($output);
    }

    protected function latestReachableSemverTag(): ?string
    {
        $output = $this->runTrimmed('git describe --tags --match "'.SemanticVersion::tagGlob().'" --abbrev=0');

        return $output === null ? null : SemanticVersion::firstTagIn($output);
    }

    protected function previousSemverTag(): ?string
    {
        $output = $this->runTrimmed('git describe --tags --match "'.SemanticVersion::tagGlob().'" --abbrev=0 HEAD~1');

        return $output === null ? null : SemanticVersion::firstTagIn($output);
    }

    /**
     * @return array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int}
     */
    protected function gatherStats(?string $buildRange): array
    {
        [$commitAdditions, $commitDeletions] = $this->sumNumstat('git log --format= --numstat -1 HEAD');
        [$buildAdditions, $buildDeletions] = $buildRange === null
            ? [0, 0]
            : $this->sumNumstat("git log --format= --numstat {$buildRange}");
        [$lifetimeAdditions, $lifetimeDeletions] = $this->sumNumstat('git log --format= --numstat');

        return [
            'total_commits' => (int) ($this->runTrimmed('git rev-list --count HEAD') ?? '0'),
            'commit_additions' => $commitAdditions,
            'commit_deletions' => $commitDeletions,
            'build_additions' => $buildAdditions,
            'build_deletions' => $buildDeletions,
            'lifetime_additions' => $lifetimeAdditions,
            'lifetime_deletions' => $lifetimeDeletions,
        ];
    }

    /**
     * The same statistics as {@see gatherStats()}, reading only the history the
     * cache has not seen: the numstat of the commits between the nearest
     * cached ancestor and HEAD, and of a build range only the first time its
     * boundary appears. Without a usable ancestor (a first run, a rewritten
     * history, a changed diff context) it reads the whole history once. A
     * failure falls back to the uncached collection unless metadata must be
     * complete, in which case it fails the same way.
     *
     * @return array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int}
     */
    protected function gatherCachedStats(?string $statsRange, StatsCache $cache): array
    {
        try {
            $head = $this->gitOutput(['rev-parse', 'HEAD']);
            $cache->useContext($this->statsContext());

            $reachable = array_flip($this->gitLines(['rev-list', $head, '--']));
            [$lifetime, $numstats] = $this->lifetimeSums($head, $reachable, $cache);
            [$commitAdditions, $commitDeletions] = $numstats[$head] ?? $this->numstatByCommit(['-1', $head])[$head] ?? [0, 0];
            [$buildAdditions, $buildDeletions] = $statsRange === null
                ? [0, 0]
                : $this->rangeSums($statsRange, $head, $reachable, $lifetime, $numstats, $cache);
        } catch (RuntimeException $e) {
            if ($this->requiresCompleteMetadata()) {
                throw $e;
            }

            return $this->gatherStats($statsRange);
        }

        return [
            'total_commits' => count($reachable),
            'commit_additions' => $commitAdditions,
            'commit_deletions' => $commitDeletions,
            'build_additions' => $buildAdditions,
            'build_deletions' => $buildDeletions,
            'lifetime_additions' => $lifetime['additions'],
            'lifetime_deletions' => $lifetime['deletions'],
        ];
    }

    /**
     * Sums over everything reachable from $head, plus the per-commit numstat of
     * whatever had to be read. The count check (cached commits + commits read
     * = commits reachable) catches entries made under another history, such
     * as a shallow clone deepened since; they are all dropped and the whole
     * history is read again.
     *
     * @param  array<string, int>  $reachable
     * @return array{0: array{additions: int, deletions: int, commits: int}, 1: array<string, array{0: int, 1: int}>}
     */
    protected function lifetimeSums(string $head, array $reachable, StatsCache $cache): array
    {
        $ancestor = $cache->nearestAncestor($reachable);
        $base = $ancestor !== null ? $cache->sums($ancestor) : null;

        if ($ancestor === $head && $base !== null && $base['commits'] === count($reachable)) {
            return [$base, []];
        }

        $numstats = $this->numstatByCommit([$ancestor !== null ? "{$ancestor}..{$head}" : $head]);

        if ($base !== null && count($reachable) !== $base['commits'] + count($numstats)) {
            $cache->forgetCommits();
            $base = null;
            $numstats = $this->numstatByCommit([$head]);
        }

        $lifetime = $base ?? ['additions' => 0, 'deletions' => 0, 'commits' => 0];

        foreach ($numstats as [$additions, $deletions]) {
            $lifetime['additions'] += $additions;
            $lifetime['deletions'] += $deletions;
        }

        $lifetime['commits'] += count($numstats);

        if ($lifetime['commits'] === count($reachable)) {
            $cache->remember($head, $lifetime['additions'], $lifetime['deletions'], $lifetime['commits']);
        }

        return [$lifetime, $numstats];
    }

    /**
     * Sums over "<boundary>..HEAD". With the boundary an ancestor of HEAD the
     * range is lifetime(HEAD) - lifetime(boundary); the first time a boundary
     * appears its range is summed from the commits already read, or read
     * directly, and the boundary's own lifetime is cached from the difference.
     *
     * @param  array<string, int>  $reachable
     * @param  array{additions: int, deletions: int, commits: int}  $lifetime
     * @param  array<string, array{0: int, 1: int}>  $numstats
     * @return array{0: int, 1: int}
     */
    protected function rangeSums(string $range, string $head, array $reachable, array $lifetime, array $numstats, StatsCache $cache): array
    {
        if (! str_ends_with($range, '..HEAD') || $range === '..HEAD') {
            return $this->sumOf($this->numstatByCommit([$range]));
        }

        $boundary = $this->gitOutput(['rev-list', '-n', '1', substr($range, 0, -strlen('..HEAD')), '--']);

        if ($boundary === $head) {
            return [0, 0];
        }

        if (! isset($reachable[$boundary])) {
            return $this->sumOf($this->numstatByCommit(["{$boundary}..{$head}"]));
        }

        $base = $cache->sums($boundary);

        if ($base !== null) {
            return [$lifetime['additions'] - $base['additions'], $lifetime['deletions'] - $base['deletions']];
        }

        $inRange = $numstats !== [] ? $this->gitLines(['rev-list', "{$boundary}..{$head}", '--']) : null;
        $rangeNumstats = $inRange !== null && array_diff_key(array_flip($inRange), $numstats) === []
            ? array_intersect_key($numstats, array_flip($inRange))
            : $this->numstatByCommit(["{$boundary}..{$head}"]);
        [$additions, $deletions] = $this->sumOf($rangeNumstats);

        if ($lifetime['commits'] === count($reachable)) {
            $cache->remember(
                $boundary,
                $lifetime['additions'] - $additions,
                $lifetime['deletions'] - $deletions,
                $lifetime['commits'] - count($rangeNumstats),
            );
        }

        return [$additions, $deletions];
    }

    /**
     * Per-commit numstat sums for a `git log` revision range, in log order;
     * merges have no numstat and sum to zero, exactly as in a plain listing.
     *
     * @param  list<string>  $revisions
     * @return array<string, array{0: int, 1: int}>
     */
    protected function numstatByCommit(array $revisions): array
    {
        $command = ['git', 'log', '--format=%H', '--numstat', ...$revisions, '--'];
        $result = Process::path(AppVersion::repositoryPath())->timeout(120)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to collect version statistics: '.implode(' ', $command));
        }

        $commits = [];
        $current = null;

        foreach (explode("\n", $result->output()) as $line) {
            if (StatsCache::isCommitHash(rtrim($line, "\r"))) {
                $current = rtrim($line, "\r");
                $commits[$current] = [0, 0];

                continue;
            }

            $parts = preg_split('/\s+/', $line, 3) ?: [];

            if ($current !== null && count($parts) >= 2 && $parts[0] !== '-') {
                $commits[$current][0] += (int) $parts[0];
                $commits[$current][1] += (int) $parts[1];
            }
        }

        return $commits;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $numstats
     * @return array{0: int, 1: int}
     */
    protected function sumOf(array $numstats): array
    {
        $additions = 0;
        $deletions = 0;

        foreach ($numstats as [$commitAdditions, $commitDeletions]) {
            $additions += $commitAdditions;
            $deletions += $commitDeletions;
        }

        return [$additions, $deletions];
    }

    /**
     * Everything besides the commits that decides what numstat prints: the
     * Git version, diff and log configuration, and the attributes that mark
     * files binary or give them a text conversion.
     */
    protected function statsContext(): string
    {
        $repository = AppVersion::repositoryPath();
        $config = Process::path($repository)->run(['git', 'config', '--get-regexp', '^(diff|log)\.|^core\.attributesfile$']);
        $attributes = [];

        foreach (['.gitattributes', '.git/info/attributes'] as $file) {
            $path = $repository.DIRECTORY_SEPARATOR.$file;
            $attributes[$file] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return hash('sha256', serialize([
            StatsCache::FORMAT,
            $this->gitOutput(['--version']),
            $config->successful() ? $config->output() : '',
            $attributes,
        ]));
    }

    /** @return list<string> */
    protected function gitLines(array $arguments): array
    {
        $output = $this->gitOutput($arguments, allowEmpty: true);

        return $output === '' ? [] : explode("\n", $output);
    }

    protected function gitOutput(array $arguments, bool $allowEmpty = false): string
    {
        $command = ['git', ...$arguments];
        $result = Process::path(AppVersion::repositoryPath())->timeout(120)->run($command);
        $output = trim(str_replace("\r\n", "\n", $result->output()));

        if (! $result->successful() || (! $allowEmpty && $output === '')) {
            throw new RuntimeException('Unable to collect version statistics: '.implode(' ', $command));
        }

        return $output;
    }

    /**
     * Identifies how earlier releases are compiled: the release-notes
     * configuration, the application name used in fallback copy, and the code
     * of the classes that turn commits into a payload. Null (no caching) when
     * the configuration cannot be fingerprinted.
     */
    protected function releaseFingerprint(ReleaseNotesPublisher $publisher): ?string
    {
        $files = [];

        foreach ([$publisher, app(ReleaseNotesCompiler::class), app(ReleaseCommitSource::class), ReleaseNote::class, SemanticVersion::class] as $class) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $file = $reflection->getFileName();

                if ($file !== false) {
                    $files[$file] = hash_file('sha256', $file);
                }
            }
        }

        try {
            return hash('sha256', serialize([
                config('app-version.release_notes'),
                config('app.name'),
                config('app-version.tag_prefix'),
                $files,
            ]));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{version: string, commit: string}  $entry
     * @param  array{version: string, commit: string}|null  $previous
     */
    protected function releaseCacheKey(string $fingerprint, array $entry, ?array $previous): string
    {
        return hash('sha256', serialize([$fingerprint, $entry['version'], $entry['commit'], $previous['version'] ?? null, $previous['commit'] ?? null]));
    }

    protected function requiresCompleteMetadata(): bool
    {
        return $this->option('strict') || $this->option('flat') || config('app-version.flat', false);
    }

    /**
     * Null when the option was not given, false when it was given empty.
     */
    protected function pathOption(string $name): string|false|null
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : false;
    }

    /**
     * Sum the additions/deletions columns of a numstat listing. Binary files
     * report "-" and are skipped.
     *
     * @return array{0: int, 1: int}
     */
    protected function sumNumstat(string $command): array
    {
        $result = Process::path(AppVersion::repositoryPath())->timeout(120)->run($command);

        if (! $result->successful()) {
            if ($this->requiresCompleteMetadata()) {
                throw new RuntimeException("Unable to collect version statistics: {$command}");
            }

            return [0, 0];
        }

        $additions = 0;
        $deletions = 0;

        foreach (explode("\n", trim($result->output())) as $line) {
            $parts = preg_split('/\s+/', $line, 3) ?: [];

            if (count($parts) >= 2 && $parts[0] !== '-') {
                $additions += (int) $parts[0];
                $deletions += (int) $parts[1];
            }
        }

        return [$additions, $deletions];
    }

    /**
     * The oldest root commit's date; a repository can have several roots
     * (grafted histories, subtree merges), so take the earliest.
     */
    protected function firstCommitAt(): ?string
    {
        $output = $this->runTrimmed('git log --max-parents=0 --format=%cI HEAD');

        if ($output === null) {
            return null;
        }

        $dates = array_filter(array_map('trim', preg_split('/\R+/', $output) ?: []));
        sort($dates);

        return $dates[0] ?? null;
    }

    protected function runTrimmed(string $command): ?string
    {
        $result = Process::path(AppVersion::repositoryPath())->run($command);

        if (! $result->successful()) {
            if ($this->requiresCompleteMetadata() && ! str_starts_with($command, 'git describe ')) {
                throw new RuntimeException("Unable to read version metadata: {$command}");
            }

            return null;
        }

        $value = trim($result->output());

        return $value !== '' ? $value : null;
    }

    /** Avoid a dirty-file loop when the next commit only checks in this snapshot. */
    protected function snapshotOnlyCommit(): bool
    {
        if (! File::isFile(AppVersion::flatPath())) {
            return false;
        }

        $relative = str_replace('\\', '/', AppVersion::flatPath());
        $root = rtrim(str_replace('\\', '/', AppVersion::repositoryPath()), '/').'/';

        if (! str_starts_with($relative, $root)) {
            return false;
        }

        $changed = $this->runTrimmed('git diff-tree --no-commit-id --name-only -r HEAD');
        $snapshot = json_decode(File::get(AppVersion::flatPath()), true);

        return $changed === substr($relative, strlen($root))
            && is_array($snapshot)
            && ($snapshot['source_commit'] ?? null) === $this->runTrimmed('git rev-parse HEAD~1');
    }
}
