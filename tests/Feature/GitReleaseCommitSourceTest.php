<?php

use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\Git\GitReleaseCommitSource;

it('merges VERSION file history with semver tags, tags winning, newest first', function (): void {
    $this->writeVersionJson('0.2.0');

    Process::fake([
        'git log --format=%H -- VERSION' => Process::result(output: "file-020\nfile-010\n"),
        'git show file-020:VERSION' => Process::result(output: "0.2.0\n"),
        'git show file-010:VERSION' => Process::result(output: "0.1.0\n"),
        'git for-each-ref refs/tags*' => Process::result(output: implode("\n", [
            'v0.0.2 tagobj-002 commit-002',
            'v0.0.10 commit-0010 ',
            'v0.1.0 tagobj-010 tag-commit-010',
            'nightly commit-nightly ',
            'v1.0.0-rc1 commit-rc ',
        ])."\n"),
        'git rev-parse HEAD' => Process::result(output: "head\n"),
    ]);

    $history = (new GitReleaseCommitSource)->versionHistory();

    expect($history)->toBe([
        ['version' => '0.2.0', 'commit' => 'file-020'],
        ['version' => '0.1.0', 'commit' => 'tag-commit-010'],
        ['version' => '0.0.10', 'commit' => 'commit-0010'],
        ['version' => '0.0.2', 'commit' => 'commit-002'],
    ]);
});

it('reads committer dates for refs', function (): void {
    Process::fake([
        'git log -1 --format=%cI abc123' => Process::result(output: "2026-08-24T18:30:00+02:00\n"),
        'git log -1 --format=%cI missing' => Process::result(exitCode: 128),
    ]);

    $source = new GitReleaseCommitSource;

    expect($source->commitDate('abc123')?->toIso8601String())->toBe('2026-08-24T18:30:00+02:00')
        ->and($source->commitDate('missing'))->toBeNull();
});

it('adds the running version at HEAD when neither file history nor tags know it', function (): void {
    $this->writeVersionJson('0.3.0');

    Process::fake([
        'git log --format=%H -- VERSION' => Process::result(output: ''),
        'git for-each-ref refs/tags*' => Process::result(output: "v0.2.0 c020 \n"),
        'git rev-parse HEAD' => Process::result(output: "head\n"),
    ]);

    expect((new GitReleaseCommitSource)->versionHistory())->toBe([
        ['version' => '0.3.0', 'commit' => 'head'],
        ['version' => '0.2.0', 'commit' => 'c020'],
    ]);
});
