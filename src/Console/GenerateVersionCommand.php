<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesPublisher;
use Medalink\AppVersion\Support\SemanticVersion;
use RuntimeException;

class GenerateVersionCommand extends Command
{
    protected $signature = 'app:version
        {--flat : Write version-info.json with statistics and full release history for committing}
        {--strict : Fail when Git metadata or diff statistics cannot be read}';

    protected $description = 'Generate version.json from the VERSION file, semver git tags, and commit statistics';

    public function handle(): int
    {
        $flat = $this->option('flat') || config('app-version.flat', false);

        if ($flat && $this->snapshotOnlyCommit()) {
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
            'stats' => $this->gatherStats($release['stats_range']),
        ];

        if ($flat) {
            $data['source_commit'] = $this->runTrimmed('git rev-parse HEAD');
            $data['release_notes'] = AppVersion::usingData($data, function () use ($full, $version, $data): array {
                $publisher = app(ReleaseNotesPublisher::class);
                $releases = [];

                foreach (array_reverse($publisher->versionHistory()) as $entry) {
                    $payload = $publisher->payloadForVersion($entry['version'], strict: true, useStoredReleases: false);

                    if ($entry['version'] === $version && $data['committed_at'] !== null) {
                        $payload['published_at'] = $data['committed_at'];
                    }

                    $releases[] = $payload;
                }

                if ($releases === []) {
                    throw new RuntimeException('No release history could be exported.');
                }

                return ['format' => 1, 'build' => $full, 'releases' => $releases];
            });
        }

        $outputPath = $flat ? AppVersion::flatPath() : AppVersion::jsonPath();
        File::ensureDirectoryExists(dirname($outputPath));
        File::replace($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        AppVersion::clearCache();

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
     * Sum the additions/deletions columns of a numstat listing. Binary files
     * report "-" and are skipped.
     *
     * @return array{0: int, 1: int}
     */
    protected function sumNumstat(string $command): array
    {
        $result = Process::path(AppVersion::repositoryPath())->timeout(120)->run($command);

        if (! $result->successful()) {
            if ($this->option('strict') || $this->option('flat') || config('app-version.flat', false)) {
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
            if (($this->option('strict') || $this->option('flat') || config('app-version.flat', false)) && ! str_starts_with($command, 'git describe ')) {
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
            && ($snapshot['source_commit'] ?? null) === $this->runTrimmed('git rev-parse HEAD^');
    }
}
