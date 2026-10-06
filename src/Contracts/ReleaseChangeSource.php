<?php

namespace Medalink\AppVersion\Contracts;

/**
 * A commit source that can also hand back whole pull requests, so release
 * notes describe the change a reviewer merged rather than every commit made
 * on the way there. Used when `release_notes.unit` is `pull_request`.
 *
 * @phpstan-type Change array{title: string, branch: string|null, number: int|null, details: list<string>}
 */
interface ReleaseChangeSource
{
    /**
     * Changes in ($fromRef, $toRef], newest first: one per merged pull
     * request (title from the merge body, details = the commit subjects it
     * brought in) plus one per commit made directly on the mainline. Pull
     * requests merged into another one (a release branch, a stack) are
     * listed on their own, not as details of the one that carried them.
     *
     * @return list<Change>
     */
    public function changes(string $fromRef, string $toRef): array;
}
