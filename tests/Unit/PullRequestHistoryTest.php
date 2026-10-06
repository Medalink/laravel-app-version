<?php

use Medalink\AppVersion\Git\PullRequestHistory;

/** One `PullRequestHistory::FORMAT` record. */
function logRecord(string $hash, string $parents, string $subject, string $body = ''): string
{
    return "{$hash}\x1f{$parents}\x1f{$subject}\x1f{$body}\x1e\n";
}

it('splits a release range into pull requests, release-branch children and direct commits', function (): void {
    // main: m1 -> pr10 (feat/widget: f1, f2, a merge of main) -> pr12 (beta: b1, pr11 (fix/thing: g1))
    $log = implode('', [
        logRecord('pr12', 'pr10 pr11', 'Merge pull request #12 from org/beta', "Release beta\n\nNotes"),
        logRecord('pr11', 'b1 g1', 'Merge pull request #11 from org/fix/thing', 'Fix the thing'),
        logRecord('g1', 'b1', 'Do the thing'),
        logRecord('b1', 'pr10', 'Fix straight on beta'),
        logRecord('pr10', 'm1 f3', 'Merge pull request #10 from org/feat/widget', 'Widget dashboard'),
        logRecord('f3', 'f2 m1', "Merge branch 'main' into feat/widget"),
        logRecord('f2', 'f1', 'Fix widget test'),
        logRecord('f1', 'm1', 'Add widget'),
        logRecord('m1', 'base', 'Direct fix on main'),
    ]);

    expect((new PullRequestHistory)->changes($log))->toBe([
        ['title' => 'Fix the thing', 'branch' => 'fix/thing', 'number' => 11, 'details' => ['Do the thing']],
        ['title' => 'Release beta', 'branch' => 'beta', 'number' => 12, 'details' => ['Fix straight on beta']],
        ['title' => 'Widget dashboard', 'branch' => 'feat/widget', 'number' => 10, 'details' => ['Add widget', 'Fix widget test']],
        ['title' => 'Direct fix on main', 'branch' => null, 'number' => null, 'details' => []],
    ]);
});

it('names a pull request with an empty merge body after its branch', function (): void {
    $log = logRecord('pr1', 'base c1', 'Merge pull request #1 from org/feat/quiet-mode')
        .logRecord('c1', 'base', 'Add quiet mode');

    expect((new PullRequestHistory)->changes($log))->toBe([
        ['title' => 'feat quiet mode', 'branch' => 'feat/quiet-mode', 'number' => 1, 'details' => ['Add quiet mode']],
    ]);
});

it('returns nothing for an empty range', function (): void {
    expect((new PullRequestHistory)->changes(''))->toBe([]);
});
