<?php

namespace Medalink\AppVersion\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * What `app:version --stats-cache=<file>` remembers between runs, so a run
 * only reads the history it has not seen yet.
 *
 * Commit entries: for each processed commit C, the numstat additions and
 * deletions summed over every commit reachable from C, and how many commits
 * that is. A commit hash names its whole history, so an entry never goes
 * stale; for an ancestor B of C, reach(C) is reach(B) plus exactly B..C, so
 * lifetime(C) = sums(B) + numstat(B..C) and a range X..C with X an ancestor
 * is sums(C) - sums(X). An entry written for a run's HEAD also keeps the
 * earliest root-commit date in that history (the next run takes the smaller
 * of it and the roots in B..C) and, once read, the `git log --format=%H` of
 * the VERSION file at C. The entries are only valid for the Git version,
 * configuration, attributes, shallow and graft files and replace refs they
 * were computed under; a different context discards them. The context holds
 * no paths, so every checkout of the repository (a linked worktree per
 * release) shares the entries. The cache also keeps every directory (from the
 * top level) above a path the entries' histories list, and a fingerprint of
 * the work tree's .gitattributes in those directories: Git looks the
 * attributes of each listed path up there, tracked or not, so another
 * fingerprint discards the entries too. An entry naming a commit the
 * repository no longer has is dropped by the run that finds it.
 *
 * VERSION contents: the VERSION file at a commit never changes, so its
 * contents are kept for the commits the kept listings name.
 *
 * Release payloads: with --flat, each earlier version's release-notes payload
 * keyed by the commits that bound it and the configuration and code that
 * compiled it (by class name and contents, not by where the code is
 * installed). The running version is never cached.
 *
 * The file is a cache: an unreadable or foreign file starts empty, and it is
 * replaced atomically so a concurrent reader never sees half a file. Several
 * processes may share it (the git hooks of every worktree of a repository do):
 * two that overlap each keep what they read, and the later write replaces the
 * earlier whole, costing at worst a re-read of history, never a wrong number,
 * because an entry is keyed by the commit that names its history.
 *
 * @phpstan-type Entry array{additions: int, deletions: int, commits: int, used: int, first_commit_at?: string, version_log?: array{path: string, commits: list<string>}}
 */
class StatsCache
{
    public const int FORMAT = 2;

    /** Most-recently used commit entries kept; boundaries are used every run. */
    public const int MAX_COMMITS = 256;

    /** Tries at renaming the new file over the old one. */
    private const int REPLACE_ATTEMPTS = 5;

    private string $context = '';

    /** @var list<string> directories (from the top level) above every path the entries' histories list */
    private array $directories = [];

    /** Fingerprint of the work tree's .gitattributes in those directories when the entries were written. */
    private string $directoryAttributes = '';

    /** @var array<string, Entry> */
    private array $commits = [];

    /** @var array<string, string|null> "<commit>:<path>" => trimmed contents, null when absent */
    private array $versionContents = [];

    /** @var array<string, array<string, mixed>> */
    private array $releases = [];

    /** @var array<string, true> */
    private array $releasesUsed = [];

    private function __construct(private readonly string $path) {}

    public static function load(string $path): self
    {
        $cache = new self($path);

        try {
            $data = File::isFile($path) ? json_decode((string) File::get($path), true) : null;
        } catch (Throwable) {
            $data = null;
        }

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            return $cache;
        }

        $cache->context = is_string($data['context'] ?? null) ? $data['context'] : '';
        $directories = $data['directories'] ?? null;

        if (is_array($directories) && array_is_list($directories) && is_string($data['directory_attributes'] ?? null)
            && array_filter($directories, static fn (mixed $directory): bool => ! is_string($directory)) === []) {
            $cache->directories = $directories;
            $cache->directoryAttributes = $data['directory_attributes'];
        } else {
            // Without them no entry can be checked against the attributes.
            $data['commits'] = [];
        }

        foreach (is_array($data['commits'] ?? null) ? $data['commits'] : [] as $sha => $entry) {
            if (self::isCommitHash((string) $sha) && is_array($entry)
                && is_int($entry['additions'] ?? null) && is_int($entry['deletions'] ?? null)
                && is_int($entry['commits'] ?? null) && $entry['commits'] > 0) {
                $cache->commits[(string) $sha] = [
                    'additions' => $entry['additions'],
                    'deletions' => $entry['deletions'],
                    'commits' => $entry['commits'],
                    'used' => is_int($entry['used'] ?? null) ? $entry['used'] : 0,
                    ...(is_string($entry['first_commit_at'] ?? null) && $entry['first_commit_at'] !== '' ? ['first_commit_at' => $entry['first_commit_at']] : []),
                    ...(self::isVersionLog($entry['version_log'] ?? null) ? ['version_log' => $entry['version_log']] : []),
                ];
            }
        }

        foreach (is_array($data['version_contents'] ?? null) ? $data['version_contents'] : [] as $key => $contents) {
            if (is_string($contents) || $contents === null) {
                $cache->versionContents[(string) $key] = $contents;
            }
        }

        foreach (is_array($data['releases'] ?? null) ? $data['releases'] : [] as $key => $payload) {
            if (is_array($payload)) {
                $cache->releases[(string) $key] = $payload;
            }
        }

        return $cache;
    }

    public static function isCommitHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/', $value) === 1;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function context(): string
    {
        return $this->context;
    }

    /**
     * Commit entries are only comparable under one Git version,
     * configuration and set of attributes; another context drops them.
     */
    public function useContext(string $context): void
    {
        if ($context !== $this->context) {
            $this->context = $context;
            $this->forgetCommits();
        }
    }

    /** @return list<string> */
    public function directories(): array
    {
        return $this->directories;
    }

    public function directoryAttributes(): string
    {
        return $this->directoryAttributes;
    }

    /**
     * The directories the entries' histories touch, and the fingerprint of
     * the attributes files in them the entries were computed under.
     *
     * @param  list<string>  $directories
     */
    public function rememberDirectories(array $directories, string $attributes): void
    {
        $this->directories = array_values($directories);
        $this->directoryAttributes = $attributes;
    }

    /**
     * Entries a run can build on: those written for a run's HEAD (with the
     * first-commit date), the most history first.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        $candidates = array_filter($this->commits, static fn (array $entry): bool => isset($entry['first_commit_at']));
        uksort($candidates, fn (string $left, string $right): int => [$this->commits[$right]['commits'], $this->commits[$right]['used'], $left]
            <=> [$this->commits[$left]['commits'], $this->commits[$left]['used'], $right]);

        return array_keys($candidates);
    }

    /**
     * @return array{additions: int, deletions: int, commits: int}|null
     */
    public function sums(string $sha): ?array
    {
        if (! isset($this->commits[$sha])) {
            return null;
        }

        $this->commits[$sha]['used'] = time();
        ['additions' => $additions, 'deletions' => $deletions, 'commits' => $commits] = $this->commits[$sha];

        return ['additions' => $additions, 'deletions' => $deletions, 'commits' => $commits];
    }

    public function firstCommitAt(string $sha): ?string
    {
        return $this->commits[$sha]['first_commit_at'] ?? null;
    }

    /** @return list<string>|null */
    public function versionLog(string $sha, string $path): ?array
    {
        $log = $this->commits[$sha]['version_log'] ?? null;

        return $log !== null && $log['path'] === $path ? $log['commits'] : null;
    }

    /**
     * Record C's sums. The first-commit date and VERSION listing are kept
     * when this call does not know them (a range boundary).
     *
     * @param  list<string>|null  $versionLog
     */
    public function remember(string $sha, int $additions, int $deletions, int $commits, ?string $firstCommitAt = null, ?string $versionPath = null, ?array $versionLog = null): void
    {
        if ($commits < 1 || ! self::isCommitHash($sha)) {
            return;
        }

        $entry = ['additions' => $additions, 'deletions' => $deletions, 'commits' => $commits, 'used' => time()];
        $previous = $this->commits[$sha] ?? [];
        $firstCommitAt ??= $previous['first_commit_at'] ?? null;

        if ($firstCommitAt !== null && $firstCommitAt !== '') {
            $entry['first_commit_at'] = $firstCommitAt;
        }

        if ($versionPath !== null && $versionLog !== null) {
            $entry['version_log'] = ['path' => $versionPath, 'commits' => array_values($versionLog)];
        } elseif (isset($previous['version_log'])) {
            $entry['version_log'] = $previous['version_log'];
        }

        $this->commits[$sha] = $entry;
    }

    /** @return list<string> every commit with an entry */
    public function commitHashes(): array
    {
        return array_map('strval', array_keys($this->commits));
    }

    /** @param list<string> $shas */
    public function forget(array $shas): void
    {
        foreach ($shas as $sha) {
            unset($this->commits[$sha]);
        }
    }

    public function forgetCommits(): void
    {
        $this->commits = [];
        $this->versionContents = [];
        $this->directories = [];
        $this->directoryAttributes = '';
    }

    /**
     * @param  list<string>  $commits
     * @return array<string, string|null> the known ones, commit => contents
     */
    public function versionContents(string $path, array $commits): array
    {
        $known = [];

        foreach ($commits as $commit) {
            if (array_key_exists("{$commit}:{$path}", $this->versionContents)) {
                $known[$commit] = $this->versionContents["{$commit}:{$path}"];
            }
        }

        return $known;
    }

    /** @param array<string, string|null> $contents commit => contents */
    public function rememberVersionContents(string $path, array $contents): void
    {
        foreach ($contents as $commit => $value) {
            if (self::isCommitHash((string) $commit)) {
                $this->versionContents["{$commit}:{$path}"] = $value;
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function release(string $key): ?array
    {
        if (! isset($this->releases[$key])) {
            return null;
        }

        $this->releasesUsed[$key] = true;

        return $this->releases[$key];
    }

    /** @param array<string, mixed> $payload */
    public function rememberRelease(string $key, array $payload): void
    {
        $this->releases[$key] = $payload;
        $this->releasesUsed[$key] = true;
    }

    /**
     * Drop release payloads this run did not ask for: versions whose bounds
     * or configuration changed. Only a run that built the history may call it.
     */
    public function forgetUnusedReleases(): void
    {
        $this->releases = array_intersect_key($this->releases, $this->releasesUsed);
    }

    public function save(): void
    {
        $commits = $this->commits;

        if (count($commits) > self::MAX_COMMITS) {
            uasort($commits, static fn (array $left, array $right): int => [$right['used'], $right['commits']] <=> [$left['used'], $left['commits']]);
            $commits = array_slice($commits, 0, self::MAX_COMMITS, true);
        }

        $named = [];

        foreach ($commits as $entry) {
            foreach ($entry['version_log']['commits'] ?? [] as $commit) {
                $named["{$commit}:{$entry['version_log']['path']}"] = true;
            }
        }

        File::ensureDirectoryExists(dirname($this->path));
        self::replaceAtomically($this->path, json_encode([
            'format' => self::FORMAT,
            'context' => $this->context,
            'directories' => $this->directories,
            'directory_attributes' => $this->directoryAttributes,
            'commits' => (object) $commits,
            'version_contents' => (object) array_intersect_key($this->versionContents, $named),
            'releases' => (object) $this->releases,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Linked worktrees share one file, so processes write it at the same time.
     * The contents go to a uniquely named file beside it and are renamed over
     * it: a reader sees the old file or the new one, never half of either, and
     * the last writer wins as a whole. Windows refuses the rename while a
     * reader has the file open, so it is retried briefly; a write that still
     * fails leaves no temporary file behind and throws (the cache is only a
     * speed-up, the caller reports and carries on).
     */
    private static function replaceAtomically(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if (file_put_contents($temporary, $contents) === false) {
                throw new RuntimeException("Could not write {$temporary}.");
            }

            for ($attempt = 1; ! @rename($temporary, $path); $attempt++) {
                if ($attempt === self::REPLACE_ATTEMPTS) {
                    throw new RuntimeException("Could not replace {$path}.");
                }

                usleep(50_000 * $attempt);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function isVersionLog(mixed $log): bool
    {
        if (! is_array($log) || ! is_string($log['path'] ?? null) || ! is_array($log['commits'] ?? null) || ! array_is_list($log['commits'])) {
            return false;
        }

        foreach ($log['commits'] as $commit) {
            if (! is_string($commit) || ! self::isCommitHash($commit)) {
                return false;
            }
        }

        return true;
    }
}
