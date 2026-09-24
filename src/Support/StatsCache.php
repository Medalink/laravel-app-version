<?php

namespace Medalink\AppVersion\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * What `app:version --stats-cache=<file>` remembers between runs, so a run
 * only reads the history it has not seen yet.
 *
 * Commit sums: for each processed commit C, the numstat additions and
 * deletions summed over every commit reachable from C, and how many commits
 * that is. A commit hash names its whole history, so an entry never goes
 * stale; for an ancestor B of C, reach(C) is reach(B) plus exactly B..C, so
 * lifetime(C) = sums(B) + numstat(B..C) and a range X..C with X an ancestor
 * is sums(C) - sums(X). The entries are only valid for the Git version,
 * diff configuration and attributes they were computed under; a different
 * context discards them.
 *
 * Release payloads: with --flat, each earlier version's release-notes payload
 * keyed by the commits that bound it and the configuration that compiled it.
 * The running version is never cached.
 *
 * The file is a cache: an unreadable or foreign file starts empty, and it is
 * replaced atomically so a concurrent reader never sees half a file.
 */
class StatsCache
{
    public const int FORMAT = 1;

    /** Most-recently used commit entries kept; boundaries are used every run. */
    public const int MAX_COMMITS = 256;

    private string $context = '';

    /** @var array<string, array{additions: int, deletions: int, commits: int, used: int}> */
    private array $commits = [];

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

        foreach (is_array($data['commits'] ?? null) ? $data['commits'] : [] as $sha => $entry) {
            if (self::isCommitHash((string) $sha) && is_array($entry)
                && is_int($entry['additions'] ?? null) && is_int($entry['deletions'] ?? null)
                && is_int($entry['commits'] ?? null) && $entry['commits'] > 0) {
                $cache->commits[(string) $sha] = [
                    'additions' => $entry['additions'],
                    'deletions' => $entry['deletions'],
                    'commits' => $entry['commits'],
                    'used' => is_int($entry['used'] ?? null) ? $entry['used'] : 0,
                ];
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

    /**
     * Commit sums are only comparable under one Git version, diff
     * configuration and set of attributes; another context drops them.
     */
    public function useContext(string $context): void
    {
        if ($context !== $this->context) {
            $this->context = $context;
            $this->commits = [];
        }
    }

    /**
     * The cached commit with the most history among $reachable (hash =>
     * anything): the ancestor that leaves the fewest commits to read.
     *
     * @param  array<string, mixed>  $reachable
     */
    public function nearestAncestor(array $reachable): ?string
    {
        $best = null;

        foreach ($this->commits as $sha => $entry) {
            if (isset($reachable[$sha]) && ($best === null || $entry['commits'] > $this->commits[$best]['commits'])) {
                $best = $sha;
            }
        }

        return $best;
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

    public function remember(string $sha, int $additions, int $deletions, int $commits): void
    {
        if ($commits < 1 || ! self::isCommitHash($sha)) {
            return;
        }

        $this->commits[$sha] = ['additions' => $additions, 'deletions' => $deletions, 'commits' => $commits, 'used' => time()];
    }

    public function forgetCommits(): void
    {
        $this->commits = [];
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

        File::ensureDirectoryExists(dirname($this->path));
        File::replace($this->path, json_encode([
            'format' => self::FORMAT,
            'context' => $this->context,
            'commits' => (object) $commits,
            'releases' => (object) $this->releases,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
