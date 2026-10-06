<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Git\GitReleaseCommitSource;
use Medalink\AppVersion\Git\IncrementalHistory;
use Medalink\AppVersion\Git\PullRequestHistory;
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

        // Every run reads the repository as it is now, not a history an
        // earlier call in this process remembered.
        $source = app(ReleaseCommitSource::class);

        if ($source instanceof GitReleaseCommitSource) {
            $source->forgetHistory();
        }

        $cache = $statsCachePath !== null ? StatsCache::load($statsCachePath) : null;
        $history = null;
        $data = null;

        if ($cache !== null) {
            try {
                $history = IncrementalHistory::read(AppVersion::repositoryPath(), $cache, AppVersion::versionFileRelativePath());
                $data = $this->generate($fallbackVersion, $flat, $cache, $history);
            } catch (RuntimeException) {
                // Whatever went wrong with the cached read is a cache miss:
                // the uncached collection below decides, under --strict and
                // --flat, whether an unreadable history fails the command. A
                // history that was read is still saved for the next run.
                $data = null;
            }
        }

        $data ??= $this->generate($fallbackVersion, $flat, null, null);

        $outputPath = $output ?? ($flat ? AppVersion::flatPath() : AppVersion::jsonPath());
        File::ensureDirectoryExists(dirname($outputPath));
        File::replace($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        AppVersion::clearCache();

        if ($cache !== null && $history !== null) {
            try {
                $history->remember();
                $cache->save();
            } catch (Throwable $e) {
                $this->warn("Could not update the stats cache at {$cache->path()}: {$e->getMessage()}");
            }
        }

        $this->info("Version: {$data['full']}");
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
     * The metadata the command writes: {@see collect()}, and with --flat the
     * source commit and every release's notes.
     *
     * @return array<string, mixed>
     */
    protected function generate(string $fallbackVersion, bool $flat, ?StatsCache $cache, ?IncrementalHistory $history): array
    {
        $data = $this->collect($fallbackVersion, $history);

        if ($flat) {
            $data['source_commit'] = $history !== null ? $history->head() : $this->runTrimmed('git rev-parse HEAD');
            $data['release_notes'] = $this->releaseNotes($data, $cache, $history);
        }

        return $data;
    }

    /**
     * Version, build, commit and statistics. Without $history every value
     * comes from its own Git command over the whole history (the reference
     * the cached run must match byte for byte); with it, from what
     * {@see IncrementalHistory} read.
     *
     * @return array{version: string, build: int, commit: string, full: string, committed_at: string|null, first_commit_at: string|null, stats: array<string, int>}
     */
    protected function collect(string $fallbackVersion, ?IncrementalHistory $history): array
    {
        $commit = $history !== null ? $history->shortHead() : ($this->runTrimmed('git rev-parse --short HEAD') ?? 'unknown');
        $release = $this->resolveRelease($fallbackVersion, $history);

        if ($release['exact_tag'] || $release['build_range'] === null) {
            $build = 0;
        } else {
            $build = $history !== null
                ? $history->rangeCount($release['build_range'])
                : (int) ($this->runTrimmed("git rev-list --count {$release['build_range']}") ?? '0');
        }

        $version = $release['version'];

        return [
            'version' => $version,
            'build' => $build,
            'commit' => $commit,
            'full' => "{$version}.{$build}+{$commit}",
            'committed_at' => $history !== null ? $history->committedAt() : $this->runTrimmed('git log -1 --format=%cI HEAD'),
            'first_commit_at' => $history !== null ? $history->firstCommitAt() : $this->firstCommitAt(),
            'stats' => $history !== null ? $history->stats($release['stats_range']) : $this->gatherStats($release['stats_range']),
        ];
    }

    /**
     * Every version's release notes, oldest first. With the stats cache the
     * history comes from what {@see IncrementalHistory} read, and earlier
     * versions (fixed by their bounding commits) are reused; the running one
     * ends at HEAD and is always compiled.
     *
     * @param  array<string, mixed>  $data
     * @return array{format: int, build: string, releases: list<array<string, mixed>>}
     */
    protected function releaseNotes(array $data, ?StatsCache $cache, ?IncrementalHistory $history): array
    {
        $version = $data['version'];
        $full = $data['full'];
        $cache = $history !== null ? $cache : null;

        $build = function () use ($full, $version, $data, $cache, $history): array {
            $publisher = app(ReleaseNotesPublisher::class);
            $versions = $publisher->versionHistory();
            $fingerprint = $cache !== null && $history !== null ? $this->releaseFingerprint($publisher, $history->context()) : null;
            $releases = [];

            foreach (array_reverse($versions, true) as $index => $entry) {
                $key = $fingerprint !== null && $entry['version'] !== $version
                    ? $this->releaseCacheKey($fingerprint, $entry, $versions[$index + 1] ?? null)
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
        };

        $source = app(ReleaseCommitSource::class);
        $notes = AppVersion::usingData($data, $history !== null && $source instanceof GitReleaseCommitSource
            ? static fn (): array => $source->usingKnownHistory(
                $history->head(),
                $history->versionFileLog(),
                $history->versionFileContents(),
                $history->tagListing(),
                $build,
            )
            : $build);
        $cache?->forgetUnusedReleases();

        return $notes;
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
    protected function resolveRelease(string $fallbackVersion, ?IncrementalHistory $history = null): array
    {
        // The cached run only reads the VERSION listing when it decides.
        $versionFileCommit = $history === null ? $this->runTrimmed('git log --format=%H -1 -- '.AppVersion::versionFileRelativePath()) : null;
        $versionFileRange = static function () use ($history, $versionFileCommit): ?string {
            $commit = $history !== null ? $history->versionFileCommit() : $versionFileCommit;

            return $commit !== null ? "{$commit}..HEAD" : null;
        };
        $exactTag = $history !== null ? $history->exactSemverTag() : $this->exactSemverTagAtHead();

        if ($exactTag !== null) {
            $previousTag = $history !== null ? $history->previousSemverTag() : $this->previousSemverTag();

            return [
                'version' => SemanticVersion::fromTag($exactTag),
                'build_range' => "{$exactTag}..HEAD",
                'stats_range' => ($previousTag ?? $exactTag).'..HEAD',
                'exact_tag' => true,
            ];
        }

        $latestTag = $history !== null ? $history->latestReachableSemverTag() : $this->latestReachableSemverTag();

        if ($latestTag === null) {
            return [
                'version' => $fallbackVersion,
                'build_range' => $versionFileRange(),
                'stats_range' => $versionFileRange(),
                'exact_tag' => false,
            ];
        }

        $tagVersion = SemanticVersion::fromTag($latestTag);

        if (SemanticVersion::compare($fallbackVersion, $tagVersion) > 0) {
            return [
                'version' => $fallbackVersion,
                'build_range' => $versionFileRange(),
                'stats_range' => $versionFileRange(),
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
     * Identifies how earlier releases are compiled: the release-notes
     * configuration, the application name used in fallback copy, the code of
     * the classes that turn commits into a payload, and the Git context
     * (configuration, replace refs) the subjects were read under. Null (no
     * caching) when the configuration cannot be fingerprinted.
     *
     * The code counts by class name and file contents, never by path: every
     * release installs its own copy of vendor/, so the same code sits at a
     * new path on each deploy.
     */
    protected function releaseFingerprint(ReleaseNotesPublisher $publisher, string $gitContext): ?string
    {
        $code = [];

        foreach ([$publisher, app(ReleaseNotesCompiler::class), app(ReleaseCommitSource::class), PullRequestHistory::class, ReleaseNote::class, SemanticVersion::class, AppVersion::class] as $class) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $file = $reflection->getFileName();

                if ($file !== false) {
                    $code[$reflection->getName()] = hash_file('sha256', $file);
                }
            }
        }

        ksort($code);

        try {
            return hash('sha256', serialize([
                config('app-version.release_notes'),
                config('app.name'),
                config('app-version.tag_prefix'),
                $code,
                $gitContext,
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
