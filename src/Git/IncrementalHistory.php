<?php

namespace Medalink\AppVersion\Git;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\Support\SemanticVersion;
use Medalink\AppVersion\Support\StatsCache;
use RuntimeException;

/**
 * The Git facts `app:version --stats-cache` writes, read so that a run costs
 * the history added since an earlier run instead of the whole history. Every
 * value equals what the uncached command reads with its own Git commands:
 *
 * - HEAD, its short hash and committer date: one `git log -1`.
 * - Tags: one `git for-each-ref`, which also answers "which semver tag points
 *   at HEAD"; `git describe` still picks the nearest reachable tag.
 * - Lifetime sums, commit count and the earliest root-commit date: the
 *   cached entry B with the most history is tried first. `git log --numstat
 *   HEAD ^B` lists B..HEAD with parents; B is an ancestor exactly when it is
 *   a parent of a listed commit (on a path from HEAD down to B, the commit
 *   just before B is B's child, so it is not reachable from B and is
 *   listed). Then lifetime(HEAD) = sums(B) +
 *   numstat(B..HEAD), and the roots of HEAD's history are B's plus the
 *   parentless commits listed. A listing that names no outside parent is all
 *   of HEAD's history (B was unrelated); otherwise other entries are tested
 *   with `git merge-base --is-ancestor`, and without one the whole history is
 *   read once.
 * - The VERSION file's `git log --format=%H -- <path>`: B's listing, when the
 *   first-parent chain from HEAD reaches B and the file is identical along
 *   it. A path-limited walk (default history simplification) follows the
 *   first parent of every commit or merge that is TREESAME to it, and shows
 *   none of them, so it pops those commits one at a time, reaches B with an
 *   empty queue and continues exactly as the walk from B. One
 *   `git diff-tree --stdin` over the chain's (commit, first parent) pairs
 *   proves the file unchanged; otherwise the walk runs.
 * - Build ranges X..HEAD, where X is a tag or VERSION commit reachable from
 *   HEAD (describe, the VERSION listing): sums(HEAD) - sums(X), X's sums
 *   being cached the first time X appears, once `git rev-list --boundary`
 *   has proven it an ancestor.
 *
 * The entries are tied to a context: the Git version, the configuration that
 * changes numstat or log output, the attributes files Git reads (the
 * repository's info/attributes through the common directory, the global and
 * system files, the top-level and every tracked .gitattributes), the shallow
 * and graft files, replace refs and the environment variables that redirect
 * them. Another context discards them. Git failures throw; the caller falls
 * back to the uncached collection.
 */
class IncrementalHistory
{
    /** Further cached commits tested as ancestors before the whole history is read. */
    public const int ANCESTOR_ATTEMPTS = 8;

    /** Environment that changes what Git reads as history or attributes. */
    private const array CONTEXT_ENVIRONMENT = [
        'GIT_ATTR_NOSYSTEM', 'GIT_ATTR_SOURCE', 'GIT_GRAFT_FILE', 'GIT_SHALLOW_FILE',
        'GIT_REPLACE_REF_BASE', 'GIT_NO_REPLACE_OBJECTS', 'GIT_DIFF_OPTS', 'GIT_CONFIG_NOSYSTEM',
    ];

    /** Configuration that changes numstat, path-limited log or subject output. */
    private const string CONTEXT_CONFIG = '/^(?:(?:diff|log|attr|i18n)\.[^=\s]*|core\.(?:attributesfile|bigfilethreshold|usereplacerefs))(?:[=\s]|$)/i';

    private string $head = '';

    private string $shortHead = '';

    private string $committedAt = '';

    private string $context = '';

    private bool $logFollows = false;

    /** false until {@see exactSemverTag()} ran */
    private string|false|null $exactTag = false;

    /** @var list<array{ref: string, short: string, object: string, peeled: string, peeled_type: string}> */
    private array $refs = [];

    private ?string $base = null;

    /** @var array<string, array{additions: int, deletions: int, date: string, parents: list<string>}> base..HEAD, or all of HEAD's history */
    private array $delta = [];

    /** @var array{additions: int, deletions: int, commits: int} */
    private array $lifetime = ['additions' => 0, 'deletions' => 0, 'commits' => 0];

    private ?string $firstCommitAt = null;

    /** @var list<string>|null */
    private ?array $versionLog = null;

    /** @var array<string, string|null> */
    private array $versionContents = [];

    /** @var array<string, InvokedProcess> */
    private array $describing = [];

    /** @var array<string, string|null> */
    private array $described = [];

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $ranges = [];

    private function __construct(
        private readonly string $repository,
        private readonly StatsCache $cache,
        private readonly string $versionPath,
    ) {}

    /**
     * Read HEAD, the context and the tags, then the history since the best
     * cached ancestor. The nearest-tag lookup starts in the background.
     */
    public static function read(string $repository, StatsCache $cache, string $versionPath): self
    {
        $history = new self($repository, $cache, $versionPath);
        $results = $history->runAll([
            'head' => ['log', '-1', '--format=%H%n%h%n%cI', 'HEAD', '--'],
            'version' => ['--version'],
            'var' => ['var', '-l'],
            'paths' => ['rev-parse', '--git-path', 'info/attributes', '--git-path', 'shallow', '--git-path', 'info/grafts', '--show-toplevel'],
            'attributes' => ['ls-files', '-s', '--', ':(glob)**/.gitattributes'],
            'refs' => ['for-each-ref', '--format=%(refname) %(refname:short) %(objectname) %(*objectname) %(*objecttype)', 'refs/tags', 'refs/replace'],
        ], optional: ['var']);

        $lines = explode("\n", $history->output($results['head']));

        if (count($lines) < 3 || ! StatsCache::isCommitHash($lines[0]) || $lines[1] === '' || $lines[2] === '') {
            throw new RuntimeException('Unable to collect version statistics: git log -1 HEAD');
        }

        [$history->head, $history->shortHead, $history->committedAt] = $lines;
        $history->readRefs($results['refs']->output());
        $cache->useContext($history->context = $history->readContext($results));

        if ($history->exactSemverTag() !== null) {
            $history->startDescribe('HEAD~1');
        } else {
            $history->startDescribe('HEAD');
        }

        $history->readDelta();

        return $history;
    }

    public function head(): string
    {
        return $this->head;
    }

    public function shortHead(): string
    {
        return $this->shortHead;
    }

    public function committedAt(): string
    {
        return $this->committedAt;
    }

    public function firstCommitAt(): ?string
    {
        return $this->firstCommitAt;
    }

    /** Fingerprint of everything besides the commits that shapes Git's output. */
    public function context(): string
    {
        return $this->context;
    }

    /**
     * `git tag --points-at HEAD --sort=-v:refname --list <glob>` narrowed to
     * the first well-formed semver tag: the only one, or Git decides.
     */
    public function exactSemverTag(): ?string
    {
        if ($this->exactTag !== false) {
            return $this->exactTag;
        }

        $matches = [];
        $ask = false;

        foreach ($this->refs as $ref) {
            if (! str_starts_with($ref['ref'], 'refs/tags/')) {
                continue;
            }

            // A tag of a tag: Git peels it fully, the listing only once.
            $ask = $ask || $ref['peeled_type'] === 'tag';
            $name = substr($ref['ref'], strlen('refs/tags/'));
            $commit = $ref['peeled'] !== '' ? $ref['peeled'] : $ref['object'];

            if ($commit === $this->head && str_starts_with($name, SemanticVersion::tagPrefix()) && SemanticVersion::isValid(SemanticVersion::fromTag($name))) {
                $matches[] = $name;
            }
        }

        // Several candidates are ordered by Git's version sort.
        return $this->exactTag = $ask || count($matches) > 1
            ? $this->gitSemverTag(['tag', '--points-at', 'HEAD', '--sort=-v:refname', '--list', SemanticVersion::tagGlob()])
            : $matches[0] ?? null;
    }

    /** `git describe --tags --match <glob> --abbrev=0`: the nearest reachable semver tag. */
    public function latestReachableSemverTag(): ?string
    {
        return $this->described('HEAD');
    }

    /** The same from HEAD~1: the tag before an exact tag on HEAD. */
    public function previousSemverTag(): ?string
    {
        return $this->described('HEAD~1');
    }

    /** `git log --format=%H -1 -- <VERSION file>` */
    public function versionFileCommit(): ?string
    {
        return $this->versionFileLog()[0] ?? null;
    }

    /**
     * `git log --format=%H -- <VERSION file>`, from the base entry when the
     * file is unchanged along the first-parent chain from HEAD to it.
     *
     * @return list<string>
     */
    public function versionFileLog(): array
    {
        if ($this->versionLog !== null) {
            return $this->versionLog;
        }

        $cached = $this->base !== null && ! $this->logFollows ? $this->cache->versionLog($this->base, $this->versionPath) : null;

        if ($cached !== null && $this->versionUnchangedSinceBase()) {
            return $this->versionLog = $cached;
        }

        $output = $this->output($this->git(['log', '--format=%H', '--', $this->versionPath]), allowEmpty: true);

        return $this->versionLog = array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    /**
     * The trimmed VERSION file at each listed commit (null when absent),
     * read with one `git cat-file --batch` for commits not cached yet.
     *
     * @return array<string, string|null>
     */
    public function versionFileContents(): array
    {
        $commits = $this->versionFileLog();
        $known = $this->cache->versionContents($this->versionPath, $commits);
        $missing = array_values(array_diff($commits, array_keys($known)));

        if ($missing !== []) {
            $read = GitReleaseCommitSource::readVersionFiles($this->repository, $this->versionPath, $missing);
            $this->versionContents += $read;
            $known += $read;
        }

        return $known;
    }

    /** The tag listing {@see GitReleaseCommitSource} parses, from the refs already read. */
    public function tagListing(): string
    {
        $lines = [];

        foreach ($this->refs as $ref) {
            if (str_starts_with($ref['ref'], 'refs/tags/')) {
                $lines[] = "{$ref['short']} {$ref['object']} {$ref['peeled']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The statistics the uncached command collects with a full-history
     * numstat, for a build range "<ref>..HEAD" (or none).
     *
     * @return array{total_commits: int, commit_additions: int, commit_deletions: int, build_additions: int, build_deletions: int, lifetime_additions: int, lifetime_deletions: int}
     */
    public function stats(?string $range): array
    {
        [$commitAdditions, $commitDeletions] = $this->commitNumstat();
        [$buildAdditions, $buildDeletions] = $range === null ? [0, 0] : $this->range($range);

        return [
            'total_commits' => $this->lifetime['commits'],
            'commit_additions' => $commitAdditions,
            'commit_deletions' => $commitDeletions,
            'build_additions' => $buildAdditions,
            'build_deletions' => $buildDeletions,
            'lifetime_additions' => $this->lifetime['additions'],
            'lifetime_deletions' => $this->lifetime['deletions'],
        ];
    }

    /** `git rev-list --count <range>` */
    public function rangeCount(string $range): int
    {
        return $this->range($range)[2];
    }

    /** Keep what this run learned for the next one. */
    public function remember(): void
    {
        $this->cache->remember(
            $this->head,
            $this->lifetime['additions'],
            $this->lifetime['deletions'],
            $this->lifetime['commits'],
            $this->firstCommitAt,
            $this->versionLog !== null ? $this->versionPath : null,
            $this->versionLog,
        );
        $this->cache->rememberVersionContents($this->versionPath, $this->versionContents);
    }

    /**
     * @param  array<string, list<string>>  $commands
     * @param  list<string>  $optional  names whose failure is returned instead of thrown
     * @return array<string, ProcessResult>
     */
    private function runAll(array $commands, array $optional = []): array
    {
        $started = [];

        foreach ($commands as $name => $arguments) {
            $started[$name] = Process::path($this->repository)->timeout(120)->start(['git', ...$arguments]);
        }

        $results = [];

        foreach ($started as $name => $process) {
            $results[$name] = $process->wait();

            if (! $results[$name]->successful() && ! in_array($name, $optional, true)) {
                throw new RuntimeException('Unable to collect version statistics: git '.implode(' ', $commands[$name]));
            }
        }

        return $results;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, ?string $input = null): ProcessResult
    {
        $process = Process::path($this->repository)->timeout(120);
        $result = ($input !== null ? $process->input($input) : $process)->run(['git', ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to collect version statistics: git '.implode(' ', $arguments));
        }

        return $result;
    }

    private function output(ProcessResult $result, bool $allowEmpty = false): string
    {
        $output = trim(str_replace("\r\n", "\n", $result->output()));

        if (! $allowEmpty && $output === '') {
            throw new RuntimeException('Unable to collect version statistics: '.$result->command());
        }

        return $output;
    }

    /** Lines of "refname short object peeled peeled-type"; the last two are empty for a lightweight tag. */
    private function readRefs(string $output): void
    {
        foreach (explode("\n", str_replace("\r\n", "\n", $output)) as $line) {
            if ($line === '') {
                continue;
            }

            [$ref, $short, $object, $peeled, $peeledType] = array_pad(explode(' ', $line, 5), 5, '');
            $this->refs[] = ['ref' => $ref, 'short' => $short, 'object' => $object, 'peeled' => $peeled, 'peeled_type' => trim($peeledType)];
        }
    }

    /** @param array<string, ProcessResult> $results */
    private function readContext(array $results): string
    {
        $variables = $results['var']->successful()
            ? $this->output($results['var'], allowEmpty: true)
            : $this->configFallback();
        $config = [];
        $attributeFiles = [];

        foreach (explode("\n", $variables) as $line) {
            if (preg_match(self::CONTEXT_CONFIG, $line) === 1) {
                $config[] = $line;
                $this->logFollows = $this->logFollows || preg_match('/^log\.follow(?:[=\s]|$)/i', $line) === 1;
            } elseif (preg_match('/^GIT_ATTR_(SYSTEM|GLOBAL)=(.+)$/', $line, $match) === 1) {
                $attributeFiles[$match[1]] = $match[2];
            }
        }

        // Git before 2.42 does not report the global attributes file.
        $attributeFiles['GLOBAL'] ??= $this->globalAttributesFile($config);
        [$info, $shallow, $grafts, $top] = array_pad(explode("\n", $this->output($results['paths'])), 4, '');
        // The work tree's top-level file counts even while it is untracked.
        $files = ['info' => $info, 'shallow' => $shallow, 'grafts' => $grafts, 'top' => $top !== '' ? $top.'/.gitattributes' : null, ...$attributeFiles];
        $tracked = $this->output($results['attributes'], allowEmpty: true);

        foreach (explode("\n", $tracked) as $line) {
            $path = explode("\t", $line, 2)[1] ?? '';

            if ($path !== '') {
                $files["tracked:{$path}"] = $path;
            }
        }

        $hashes = [];

        foreach ($files as $name => $file) {
            $absolute = $file !== null && $file !== '' && ! preg_match('#^(?:/|[A-Za-z]:[\\\\/])#', $file)
                ? $this->repository.DIRECTORY_SEPARATOR.$file
                : $file;
            $hashes[$name] = [$file, $absolute !== null && $absolute !== '' && is_file($absolute) ? hash_file('sha256', $absolute) : null];
        }

        $replaceRefs = array_values(array_filter($this->refs, static fn (array $ref): bool => ! str_starts_with($ref['ref'], 'refs/tags/')));
        $environment = [];

        foreach (self::CONTEXT_ENVIRONMENT as $name) {
            $environment[$name] = getenv($name);
        }

        return hash('sha256', serialize([
            StatsCache::FORMAT,
            $this->output($results['version']),
            $config,
            $hashes,
            $tracked,
            $replaceRefs,
            $environment,
        ]));
    }

    private function configFallback(): string
    {
        $result = Process::path($this->repository)->timeout(120)->run([
            'git', 'config', '--get-regexp', '^(diff|log|attr|i18n)\.|^core\.(attributesfile|bigfilethreshold|usereplacerefs)$',
        ]);

        // Exit status 1 only means that no key matched.
        if (! $result->successful() && $result->exitCode() !== 1) {
            throw new RuntimeException('Unable to collect version statistics: git config --get-regexp');
        }

        return trim(str_replace("\r\n", "\n", $result->output()));
    }

    /** @param list<string> $config */
    private function globalAttributesFile(array $config): ?string
    {
        foreach ($config as $line) {
            if (preg_match('/^core\.attributesfile[=\s](.+)$/i', $line, $match) === 1) {
                $file = trim($match[1]);

                return str_starts_with($file, '~/') && getenv('HOME') !== false ? getenv('HOME').substr($file, 1) : $file;
            }
        }

        $xdg = getenv('XDG_CONFIG_HOME');

        if (is_string($xdg) && $xdg !== '') {
            return $xdg.'/git/attributes';
        }

        $home = getenv('HOME');

        return is_string($home) && $home !== '' ? $home.'/.config/git/attributes' : null;
    }

    private function startDescribe(string $revision): void
    {
        $hasTags = array_filter($this->refs, static fn (array $ref): bool => str_starts_with($ref['ref'], 'refs/tags/')) !== [];

        if (! $hasTags) {
            // `git describe --tags` finds nothing to describe with.
            $this->described[$revision] = null;

            return;
        }

        $this->describing[$revision] = Process::path($this->repository)->timeout(120)->start([
            'git', 'describe', '--tags', '--match', SemanticVersion::tagGlob(), '--abbrev=0', ...($revision === 'HEAD' ? [] : [$revision]),
        ]);
    }

    private function described(string $revision): ?string
    {
        if (! array_key_exists($revision, $this->described)) {
            if (! isset($this->describing[$revision])) {
                $this->startDescribe($revision);
            }

            $result = isset($this->describing[$revision]) ? $this->describing[$revision]->wait() : null;
            $output = $result !== null && $result->successful() ? trim($result->output()) : '';
            $this->described[$revision] = $output !== '' ? SemanticVersion::firstTagIn($output) : null;
        }

        return $this->described[$revision];
    }

    /** @param list<string> $arguments */
    private function gitSemverTag(array $arguments): ?string
    {
        $output = $this->output($this->git($arguments), allowEmpty: true);

        return $output === '' ? null : SemanticVersion::firstTagIn($output);
    }

    private function readDelta(): void
    {
        $candidates = $this->cache->candidates();

        if (in_array($this->head, $candidates, true)) {
            $this->base = $this->head;
        } elseif ($candidates === []) {
            $this->delta = $this->listCommits([$this->head]);
        } else {
            $this->delta = $this->listCommits([$this->head, '^'.$candidates[0]]);

            if ($this->listsParent($candidates[0])) {
                $this->base = $candidates[0];
            } elseif ($this->delta === [] || ! $this->isClosed()) {
                // B shares history with HEAD without being its ancestor (or
                // HEAD is B's ancestor): find an entry that is one.
                $this->delta = [];

                foreach (array_slice($candidates, 1, self::ANCESTOR_ATTEMPTS) as $candidate) {
                    if ($this->isAncestor($candidate)) {
                        $this->base = $candidate;
                        $this->delta = $this->listCommits([$this->head, '^'.$candidate]);

                        break;
                    }
                }

                if ($this->base === null) {
                    $this->delta = $this->listCommits([$this->head]);
                }
            }
        }

        $sums = $this->base !== null ? $this->cache->sums($this->base) : null;
        $lifetime = $sums ?? ['additions' => 0, 'deletions' => 0, 'commits' => 0];
        $roots = $this->base !== null ? [$this->cache->firstCommitAt($this->base)] : [];

        foreach ($this->delta as $commit) {
            $lifetime['additions'] += $commit['additions'];
            $lifetime['deletions'] += $commit['deletions'];

            if ($commit['parents'] === []) {
                $roots[] = $commit['date'];
            }
        }

        $lifetime['commits'] += count($this->delta);
        $this->lifetime = $lifetime;
        $roots = array_values(array_filter($roots, static fn (?string $date): bool => $date !== null && $date !== ''));
        usort($roots, strcmp(...));
        $this->firstCommitAt = $roots[0] ?? null;
    }

    private function listsParent(string $commit): bool
    {
        foreach ($this->delta as $listed) {
            if (in_array($commit, $listed['parents'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Every parent of a listed commit is listed: the listing is a whole history. */
    private function isClosed(): bool
    {
        foreach ($this->delta as $listed) {
            foreach ($listed['parents'] as $parent) {
                if (! isset($this->delta[$parent])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isAncestor(string $commit): bool
    {
        $result = Process::path($this->repository)->timeout(120)->run(['git', 'merge-base', '--is-ancestor', $commit, $this->head]);

        if ($result->exitCode() > 1) {
            throw new RuntimeException('Unable to collect version statistics: git merge-base --is-ancestor');
        }

        return $result->successful();
    }

    /**
     * Per-commit numstat sums, committer dates and parents for a revision
     * range; merges have no numstat and sum to zero, exactly as in a plain
     * listing.
     *
     * @param  list<string>  $revisions
     * @return array<string, array{additions: int, deletions: int, date: string, parents: list<string>}>
     */
    private function listCommits(array $revisions): array
    {
        $output = $this->git(['log', '--format=@%H %cI %P', '--numstat', ...$revisions, '--'])->output();
        $commits = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $output)) as $line) {
            if (str_starts_with($line, '@')) {
                $parts = explode(' ', rtrim(substr($line, 1)));
                $current = $parts[0];
                $commits[$current] = ['additions' => 0, 'deletions' => 0, 'date' => $parts[1] ?? '', 'parents' => array_slice($parts, 2)];

                continue;
            }

            $parts = preg_split('/\s+/', $line, 3) ?: [];

            if ($current !== null && count($parts) >= 2 && $parts[0] !== '-') {
                $commits[$current]['additions'] += (int) $parts[0];
                $commits[$current]['deletions'] += (int) $parts[1];
            }
        }

        return $commits;
    }

    /** @return array{0: int, 1: int} */
    private function commitNumstat(): array
    {
        $commit = $this->delta[$this->head] ?? $this->listCommits(['-1', $this->head])[$this->head] ?? null;

        return $commit !== null ? [$commit['additions'], $commit['deletions']] : [0, 0];
    }

    /**
     * Sums and commit count of "<ref>..HEAD". For a ref reachable from HEAD
     * it is sums(HEAD) - sums(ref); the first time a ref appears its range
     * is summed from the commits already listed, or read, and the ref's own
     * sums are cached once `git rev-list --boundary` shows it is an ancestor.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function range(string $range): array
    {
        if (isset($this->ranges[$range])) {
            return $this->ranges[$range];
        }

        if (! str_ends_with($range, '..HEAD') || $range === '..HEAD') {
            return $this->ranges[$range] = $this->sumOf($this->listCommits([$range]));
        }

        $boundary = $this->commitOf(substr($range, 0, -strlen('..HEAD')));

        if ($boundary === $this->head) {
            return $this->ranges[$range] = [0, 0, 0];
        }

        $base = $this->cache->sums($boundary);

        if ($base !== null) {
            return $this->ranges[$range] = [
                $this->lifetime['additions'] - $base['additions'],
                $this->lifetime['deletions'] - $base['deletions'],
                $this->lifetime['commits'] - $base['commits'],
            ];
        }

        $inRange = [];
        $ancestor = false;

        foreach (explode("\n", $this->output($this->git(['rev-list', '--boundary', "{$boundary}..{$this->head}", '--']), allowEmpty: true)) as $line) {
            if ($line === "-{$boundary}") {
                $ancestor = true;
            } elseif ($line !== '' && $line[0] !== '-') {
                $inRange[$line] = true;
            }
        }

        $listed = array_diff_key($inRange, $this->delta) === []
            ? array_intersect_key($this->delta, $inRange)
            : $this->listCommits(["{$boundary}..{$this->head}"]);
        $sums = $this->sumOf($listed);

        if ($ancestor) {
            $this->cache->remember(
                $boundary,
                $this->lifetime['additions'] - $sums[0],
                $this->lifetime['deletions'] - $sums[1],
                $this->lifetime['commits'] - $sums[2],
            );
        }

        return $this->ranges[$range] = $sums;
    }

    /** The commit a tag name or hash names. */
    private function commitOf(string $ref): string
    {
        if (StatsCache::isCommitHash($ref)) {
            return $ref;
        }

        foreach ($this->refs as $listed) {
            if ($listed['ref'] === "refs/tags/{$ref}" && $listed['peeled_type'] !== 'tag') {
                return $listed['peeled'] !== '' ? $listed['peeled'] : $listed['object'];
            }
        }

        return $this->output($this->git(['rev-list', '-n', '1', $ref, '--']));
    }

    /**
     * @param  array<string, array{additions: int, deletions: int, date: string, parents: list<string>}>  $commits
     * @return array{0: int, 1: int, 2: int}
     */
    private function sumOf(array $commits): array
    {
        $sums = [0, 0, count($commits)];

        foreach ($commits as $commit) {
            $sums[0] += $commit['additions'];
            $sums[1] += $commit['deletions'];
        }

        return $sums;
    }

    /**
     * The first-parent chain from HEAD reaches the base within the listed
     * commits, and no link of it changes the VERSION file.
     */
    private function versionUnchangedSinceBase(): bool
    {
        $pairs = [];

        for ($commit = $this->head; $commit !== $this->base; $commit = $parent) {
            $parent = $this->delta[$commit]['parents'][0] ?? null;

            if ($parent === null || count($pairs) >= count($this->delta)) {
                return false;
            }

            $pairs[] = "{$commit} {$parent}\n";
        }

        if ($pairs === []) {
            return true;
        }

        $output = $this->git(['diff-tree', '--stdin', '-r', '--name-only', '--', $this->versionPath], implode('', $pairs))->output();

        return trim($output) === '';
    }
}
