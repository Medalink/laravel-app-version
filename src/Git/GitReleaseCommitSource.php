<?php

namespace Medalink\AppVersion\Git;

use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Support\SemanticVersion;
use RuntimeException;

class GitReleaseCommitSource implements ReleaseCommitSource
{
    /** @var list<array{version: string, commit: string}>|null */
    protected ?array $versionHistory = null;

    public function versionHistory(): array
    {
        if ($this->versionHistory !== null) {
            return $this->versionHistory;
        }

        $result = Process::path(AppVersion::repositoryPath())
            ->run('git log --format=%H -- '.AppVersion::versionFileRelativePath());

        if (! $result->successful()) {
            throw new RuntimeException('Unable to read VERSION history from git.');
        }

        $history = [];
        $seen = [];

        foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $commit) {
            $version = $this->versionAtCommit($commit);

            if ($version === null || isset($seen[$version])) {
                continue;
            }

            $history[] = ['version' => $version, 'commit' => $commit];
            $seen[$version] = true;
        }

        $currentVersion = AppVersion::version();
        $currentCommit = $this->currentCommit();

        if ($currentCommit !== null && ! isset($seen[$currentVersion])) {
            array_unshift($history, ['version' => $currentVersion, 'commit' => $currentCommit]);
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
