<?php

namespace Medalink\AppVersion\Tests\Fixtures;

use Carbon\CarbonInterface;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use RuntimeException;

class FakeReleaseCommitSource implements ReleaseCommitSource
{
    /** @var array<string, CarbonInterface> ref => committer date */
    public array $dates = [];

    /** @var list<array{version: string, commit: string}> */
    public array $history = [];

    /** @var array<string, list<string>> keyed "from..to" or "recent:to" */
    public array $subjects = [];

    /** @var array<string, string> */
    public array $tags = [];

    public ?string $currentCommitValue = 'head-commit';

    public bool $throwOnSubjects = false;

    public function versionHistory(): array
    {
        return $this->history;
    }

    public function commitSubjects(?string $fromRef, string $toRef): array
    {
        if ($this->throwOnSubjects) {
            throw new RuntimeException('simulated commit lookup failure');
        }

        $key = $fromRef !== null ? "{$fromRef}..{$toRef}" : "recent:{$toRef}";

        return $this->subjects[$key] ?? [];
    }

    public function currentCommit(): ?string
    {
        return $this->currentCommitValue;
    }

    public function tagCommit(string $version): ?string
    {
        return $this->tags[$version] ?? null;
    }

    public function commitDate(string $ref): ?CarbonInterface
    {
        return $this->dates[$ref] ?? null;
    }
}
