<?php

namespace Medalink\AppVersion\Contracts;

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
}
