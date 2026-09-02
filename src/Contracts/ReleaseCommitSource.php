<?php

namespace Medalink\AppVersion\Contracts;

use Carbon\CarbonInterface;

interface ReleaseCommitSource
{
    /**
     * Versions newest first with the commit that introduced each one.
     *
     * @return list<array{version: string, commit: string}>
     */
    public function versionHistory(): array;

    /**
     * Commit subjects in ($fromRef, $toRef]; a null $fromRef means "the most
     * recent handful ending at $toRef".
     *
     * @return list<string>
     */
    public function commitSubjects(?string $fromRef, string $toRef): array;

    public function currentCommit(): ?string;

    public function tagCommit(string $version): ?string;

    /**
     * Committer date of a ref, used as the published date of backfilled
     * releases so history carries real dates rather than the backfill time.
     */
    public function commitDate(string $ref): ?CarbonInterface;
}
