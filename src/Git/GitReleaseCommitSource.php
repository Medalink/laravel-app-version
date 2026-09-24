<?php

namespace Medalink\AppVersion\Git;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Support\SemanticVersion;
use RuntimeException;
use Throwable;

/**
 * Version boundaries come from two places, merged and sorted newest first:
 * every commit that changed the VERSION file, and every semver tag. A tag
 * wins over a VERSION change for the same version, since a tag is an
 * explicit release point (and lets history be reconstructed retroactively
 * on repositories that never bumped VERSION).
 */
class GitReleaseCommitSource implements ReleaseCommitSource
{
    /** @var list<array{version: string, commit: string}>|null */
    protected ?array $versionHistory = null;

    /**
     * Git output a caller already read, served instead of running Git again:
     * HEAD, the VERSION file's `git log --format=%H` listing and contents,
     * and the tag listing in {@see versionsFromTags()}'s format.
     *
     * @var array{head: string, version_file_commits: list<string>, version_file_contents: array<string, string|null>, tags: string}|null
     */
    protected ?array $known = null;

    /**
     * Read the history again on the next call; `app:version` starts every
     * run from the repository as it is now.
     */
    public function forgetHistory(): void
    {
        $this->versionHistory = null;
    }

    /**
     * Run $callback with {@see versionHistory()} and {@see currentCommit()}
     * built from Git output the caller already has (`app:version
     * --stats-cache` keeps it between runs) rather than from new processes.
     * The result is exactly what the same Git commands would return.
     *
     * @template T
     *
     * @param  list<string>  $versionFileCommits  `git log --format=%H -- <VERSION file>`
     * @param  array<string, string|null>  $versionFileContents  commit => trimmed VERSION contents, null when absent
     * @param  string  $tags  `git for-each-ref refs/tags --format="%(refname:short) %(objectname) %(*objectname)"`
     * @param  Closure(): T  $callback
     * @return T
     */
    public function usingKnownHistory(string $head, array $versionFileCommits, array $versionFileContents, string $tags, Closure $callback): mixed
    {
        [$known, $history] = [$this->known, $this->versionHistory];
        $this->known = [
            'head' => $head,
            'version_file_commits' => $versionFileCommits,
            'version_file_contents' => $versionFileContents,
            'tags' => $tags,
        ];
        $this->versionHistory = null;

        try {
            return $callback();
        } finally {
            [$this->known, $this->versionHistory] = [$known, $history];
        }
    }

    public function versionHistory(): array
    {
        if ($this->versionHistory !== null) {
            return $this->versionHistory;
        }

        $byVersion = $this->versionsFromFileHistory();

        foreach ($this->versionsFromTags() as $version => $commit) {
            $byVersion[$version] = $commit;
        }

        $currentVersion = AppVersion::version();
        $currentCommit = $this->currentCommit();

        if ($currentCommit !== null && ! isset($byVersion[$currentVersion]) && SemanticVersion::isValid($currentVersion)) {
            $byVersion[$currentVersion] = $currentCommit;
        }

        uksort($byVersion, static fn (string $left, string $right): int => SemanticVersion::compare($right, $left));

        $history = [];

        foreach ($byVersion as $version => $commit) {
            $history[] = ['version' => $version, 'commit' => $commit];
        }

        return $this->versionHistory = $history;
    }

    public function commitSubjects(?string $fromRef, string $toRef): array
    {
        $command = $fromRef !== null
            ? sprintf('git log --format=%%s %s..%s', $fromRef, $toRef)
            : sprintf(
                'git log --format=%%s -n %d %s',
                (int) config('app-version.release_notes.limits.initial_range_commits', 25),
                $toRef,
            );

        $result = Process::path(AppVersion::repositoryPath())->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to read git commit subjects.');
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
    }

    public function currentCommit(): ?string
    {
        if ($this->known !== null) {
            return $this->known['head'];
        }

        return $this->runTrimmed('git rev-parse HEAD');
    }

    public function tagCommit(string $version): ?string
    {
        return $this->runTrimmed('git rev-list -n 1 '.SemanticVersion::tag($version));
    }

    public function commitDate(string $ref): ?CarbonInterface
    {
        $date = $this->runTrimmed(sprintf('git log -1 --format=%%cI %s', $ref));

        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string> version => commit that introduced it
     */
    protected function versionsFromFileHistory(): array
    {
        if ($this->known !== null) {
            $commits = $this->known['version_file_commits'];
            $contents = $this->known['version_file_contents'];
        } else {
            $result = Process::path(AppVersion::repositoryPath())
                ->run('git log --format=%H -- '.AppVersion::versionFileRelativePath());

            if (! $result->successful()) {
                throw new RuntimeException('Unable to read VERSION history from git.');
            }

            $commits = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
            $contents = $this->versionFileContents($commits);
        }

        $versions = [];

        foreach ($commits as $commit) {
            $version = $contents[$commit] ?? null;

            if (! SemanticVersion::isValid($version) || isset($versions[$version])) {
                continue;
            }

            $versions[$version] = $commit;
        }

        return $versions;
    }

    /**
     * The trimmed VERSION file at each commit, read in one `git cat-file`
     * batch rather than one `git show` per commit; null where the file is
     * absent at that commit.
     *
     * @param  list<string>  $commits
     * @return array<string, string|null>
     */
    protected function versionFileContents(array $commits): array
    {
        return self::readVersionFiles(AppVersion::repositoryPath(), AppVersion::versionFileRelativePath(), $commits);
    }

    /**
     * {@see versionFileContents()} for any repository and path.
     *
     * @param  list<string>  $commits
     * @return array<string, string|null>
     */
    public static function readVersionFiles(string $repository, string $path, array $commits): array
    {
        if ($commits === []) {
            return [];
        }

        $result = Process::path($repository)
            ->input(implode('', array_map(static fn (string $commit): string => "{$commit}:{$path}\n", $commits)))
            ->run('git cat-file --batch');

        if (! $result->successful()) {
            throw new RuntimeException('Unable to read VERSION history from git.');
        }

        $output = $result->output();
        $offset = 0;
        $contents = [];

        foreach ($commits as $commit) {
            $end = strpos($output, "\n", $offset);

            if ($end === false) {
                break;
            }

            $header = substr($output, $offset, $end - $offset);
            $offset = $end + 1;

            if (preg_match('/^[0-9a-f]+ \S+ (\d+)$/', $header, $match) !== 1) {
                $contents[$commit] = null;

                continue;
            }

            $value = trim(substr($output, $offset, (int) $match[1]));
            $contents[$commit] = $value !== '' ? $value : null;
            $offset += (int) $match[1] + 1;
        }

        return $contents;
    }

    /**
     * Annotated tags peel to their commit through %(*objectname); lightweight
     * tags carry the commit in %(objectname).
     *
     * @return array<string, string> version => tagged commit
     */
    protected function versionsFromTags(): array
    {
        if ($this->known !== null) {
            $output = $this->known['tags'];
        } else {
            $result = Process::path(AppVersion::repositoryPath())->run(
                'git for-each-ref refs/tags --format="%(refname:short) %(objectname) %(*objectname)"',
            );

            if (! $result->successful()) {
                return [];
            }

            $output = $result->output();
        }

        $versions = [];

        foreach (array_filter(array_map('trim', explode("\n", $output))) as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];
            $tag = $parts[0] ?? '';
            $version = SemanticVersion::fromTag($tag);

            if (! str_starts_with($tag, SemanticVersion::tagPrefix()) || ! SemanticVersion::isValid($version)) {
                continue;
            }

            $commit = ($parts[2] ?? '') !== '' ? $parts[2] : ($parts[1] ?? '');

            if ($commit !== '') {
                $versions[$version] = $commit;
            }
        }

        return $versions;
    }

    protected function runTrimmed(string $command): ?string
    {
        $result = Process::path(AppVersion::repositoryPath())->run($command);

        if (! $result->successful()) {
            return null;
        }

        $value = trim($result->output());

        return $value !== '' ? $value : null;
    }
}
