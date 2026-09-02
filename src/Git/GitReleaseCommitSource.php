<?php

namespace Medalink\AppVersion\Git;

use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Support\SemanticVersion;
use RuntimeException;

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
        return $this->runTrimmed('git rev-parse HEAD');
    }

    public function tagCommit(string $version): ?string
    {
        return $this->runTrimmed('git rev-list -n 1 '.SemanticVersion::tag($version));
    }

    /**
     * @return array<string, string> version => commit that introduced it
     */
    protected function versionsFromFileHistory(): array
    {
        $result = Process::path(AppVersion::repositoryPath())
            ->run('git log --format=%H -- '.AppVersion::versionFileRelativePath());

        if (! $result->successful()) {
            throw new RuntimeException('Unable to read VERSION history from git.');
        }

        $versions = [];

        foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $commit) {
            $version = $this->versionAtCommit($commit);

            if ($version === null || isset($versions[$version])) {
                continue;
            }

            $versions[$version] = $commit;
        }

        return $versions;
    }

    /**
     * Annotated tags peel to their commit through %(*objectname); lightweight
     * tags carry the commit in %(objectname).
     *
     * @return array<string, string> version => tagged commit
     */
    protected function versionsFromTags(): array
    {
        $result = Process::path(AppVersion::repositoryPath())->run(
            'git for-each-ref refs/tags --format="%(refname:short) %(objectname) %(*objectname)"',
        );

        if (! $result->successful()) {
            return [];
        }

        $versions = [];

        foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $line) {
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

    protected function versionAtCommit(string $commit): ?string
    {
        $version = $this->runTrimmed(sprintf('git show %s:%s', $commit, AppVersion::versionFileRelativePath()));

        return SemanticVersion::isValid($version) ? $version : null;
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
