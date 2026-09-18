<?php

namespace Medalink\AppVersion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Models\ReleaseNoteRead;

/**
 * Runtime reader for the version metadata `app:version` writes. The JSON is
 * read once per process; call {@see clearCache()} in tests that rewrite it.
 */
class AppVersion
{
    public const string DEV_COMMIT = 'dev';

    /** @var array<string, mixed>|null */
    private static ?array $cached = null;

    /** @var array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int} */
    private const array DEFAULT_STATS = [
        'total_commits' => 0,
        'commit_additions' => 0,
        'commit_deletions' => 0,
        'build_additions' => 0,
        'build_deletions' => 0,
        'lifetime_additions' => 0,
        'lifetime_deletions' => 0,
    ];

    /**
     * @return array{version: string, build: int, commit: string, full: string, stats?: array<string, int>}
     */
    public static function data(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $fromJson = self::readJson();

        if ($fromJson !== null) {
            return self::$cached = $fromJson;
        }

        $version = self::versionFromFile();

        return self::$cached = [
            'version' => $version,
            'build' => 0,
            'commit' => self::DEV_COMMIT,
            'full' => "{$version}.0+".self::DEV_COMMIT,
        ];
    }

    /**
     * The full version string, e.g. "2.0.0.47+a3f8c2d".
     */
    public static function full(): string
    {
        return (string) self::data()['full'];
    }

    /**
     * The semantic version, e.g. "2.0.0".
     */
    public static function version(): string
    {
        return (string) self::data()['version'];
    }

    /**
     * Commits since the version boundary (semver tag or VERSION change).
     */
    public static function build(): int
    {
        return (int) self::data()['build'];
    }

    /**
     * Short commit hash, e.g. "a3f8c2d".
     */
    public static function commit(): string
    {
        return (string) self::data()['commit'];
    }

    /**
     * ISO-8601 committer date of HEAD when generated from git, else null.
     */
    public static function committedAt(): ?string
    {
        $value = self::data()['committed_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * ISO-8601 committer date of the repository's first commit, else null.
     */
    public static function firstCommitAt(): ?string
    {
        $value = self::data()['first_commit_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whole days from the repository's first commit to the running commit,
     * at least 1; null when either date is unknown or unparseable.
     */
    public static function commitSpanDays(): ?int
    {
        $first = self::firstCommitAt();
        $last = self::committedAt();

        if ($first === null || $last === null) {
            return null;
        }

        try {
            $seconds = Carbon::parse($last)->getTimestamp() - Carbon::parse($first)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        return max(1, (int) ceil($seconds / 86400));
    }

    /**
     * "563 commits in 14 days", or just "563 commits" without dates.
     */
    public static function commitSpanLabel(): string
    {
        $commits = self::stats()['total_commits'];
        $label = number_format($commits).' '.($commits === 1 ? 'commit' : 'commits');
        $days = self::commitSpanDays();

        if ($days === null) {
            return $label;
        }

        return $label.' in '.number_format($days).' '.($days === 1 ? 'day' : 'days');
    }

    /**
     * @return array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int}
     */
    public static function stats(): array
    {
        $stats = array_merge(self::DEFAULT_STATS, self::data()['stats'] ?? []);

        return array_map(intval(...), array_intersect_key($stats, self::DEFAULT_STATS));
    }

    /**
     * Everything a client needs to render the version and its breakdown.
     *
     * @return array{version: string, build: int, commit: string, full: string, committedAt: string|null, firstCommitAt: string|null, commitSpanDays: int|null, stats: array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int}}
     */
    public static function toArray(): array
    {
        return [
            'version' => self::version(),
            'build' => self::build(),
            'commit' => self::commit(),
            'full' => self::full(),
            'committedAt' => self::committedAt(),
            'firstCommitAt' => self::firstCommitAt(),
            'commitSpanDays' => self::commitSpanDays(),
            'stats' => self::stats(),
        ];
    }

    public static function clearCache(): void
    {
        self::$cached = null;
    }

    public static function jsonPath(): string
    {
        return (string) config('app-version.json_path');
    }

    public static function flatPath(): string
    {
        return (string) config('app-version.flat_path', base_path('version-info.json'));
    }

    /** @param array<string, mixed> $data */
    public static function usingData(array $data, \Closure $callback): mixed
    {
        $previous = self::$cached;
        self::$cached = $data;

        try {
            return $callback();
        } finally {
            self::$cached = $previous;
        }
    }

    public static function versionFile(): string
    {
        return (string) config('app-version.version_file');
    }

    public static function repositoryPath(): string
    {
        return (string) config('app-version.repository_path');
    }

    /**
     * The VERSION file path as git sees it from the repository root.
     */
    public static function versionFileRelativePath(): string
    {
        $file = str_replace('\\', '/', self::versionFile());
        $root = rtrim(str_replace('\\', '/', self::repositoryPath()), '/');

        if ($root !== '' && str_starts_with($file, $root.'/')) {
            return substr($file, strlen($root) + 1);
        }

        return basename($file);
    }

    /** @return class-string<ReleaseNote> */
    public static function releaseNoteModel(): string
    {
        return (string) config('app-version.models.release_note', ReleaseNote::class);
    }

    /** @return class-string<ReleaseNoteRead> */
    public static function releaseNoteReadModel(): string
    {
        return (string) config('app-version.models.release_note_read', ReleaseNoteRead::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(): ?array
    {
        $path = config('app-version.flat', false) ? self::flatPath() : self::jsonPath();

        if ($path === '' || ! File::exists($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        if (! is_array($data) || ! isset($data['version'])) {
            return null;
        }

        return $data;
    }

    private static function versionFromFile(): string
    {
        $path = self::versionFile();

        if ($path === '' || ! File::exists($path)) {
            return '0.0.0';
        }

        $version = trim((string) File::get($path));

        return $version !== '' ? $version : '0.0.0';
    }
}
