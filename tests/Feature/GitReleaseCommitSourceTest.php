<?php

use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\Git\GitReleaseCommitSource;

it('merges VERSION file history with semver tags, tags winning, newest first', function (): void {
    $this->writeVersionJson('0.2.0');

    Process::fake([
        'git log --format=%H -- VERSION' => Process::result(output: "file-020\nfile-010\nfile-gone\nfile-junk\n"),
        'git cat-file --batch' => Process::result(output: implode('', [
            "aaa020 blob 6\n0.2.0\n\n",
            "aaa010 blob 5\n0.1.0\n",
            "file-gone:VERSION missing\n",
            "aaa999 blob 9\nnot semv\n\n",
        ])),
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

    Process::assertRan(fn ($process): bool => $process->command === 'git cat-file --batch'
        && $process->input === "file-020:VERSION\nfile-010:VERSION\nfile-gone:VERSION\nfile-junk:VERSION\n");
    Process::assertNotRan(fn ($process): bool => str_starts_with((string) $process->command, 'git show'));
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

it('builds the history from Git output the caller already read, only for the callback', function (): void {
    $this->writeVersionJson('0.3.0');
    Process::fake([
        'git log --format=%H -- VERSION' => Process::result(output: "file-020\n"),
        'git cat-file --batch' => Process::result(output: "aaa020 blob 6\n0.2.0\n\n"),
        'git for-each-ref refs/tags*' => Process::result(output: ''),
        'git rev-parse HEAD' => Process::result(output: "git-head\n"),
    ]);
    $source = new GitReleaseCommitSource;

    $known = $source->usingKnownHistory(
        'known-head',
        ['file-020', 'file-010'],
        ['file-020' => '0.2.0', 'file-010' => '0.1.0'],
        "v0.1.0 tagobj-010 tag-commit-010\nv0.0.9 commit-009 ",
        fn (): array => [$source->versionHistory(), $source->currentCommit()],
    );

    expect($known)->toBe([[
        ['version' => '0.3.0', 'commit' => 'known-head'],
        ['version' => '0.2.0', 'commit' => 'file-020'],
        ['version' => '0.1.0', 'commit' => 'tag-commit-010'],
        ['version' => '0.0.9', 'commit' => 'commit-009'],
    ], 'known-head']);
    Process::assertNothingRan();

    // Afterwards the source reads Git again.
    expect($source->versionHistory())->toBe([
        ['version' => '0.3.0', 'commit' => 'git-head'],
        ['version' => '0.2.0', 'commit' => 'file-020'],
    ]);
});

it('reads the history again after forgetting it', function (): void {
    $this->writeVersionJson('0.2.0');
    Process::fake([
        'git log --format=%H -- VERSION' => Process::result(output: ''),
        'git for-each-ref refs/tags*' => Process::sequence([
            Process::result(output: "v0.1.0 c010 \n"),
            Process::result(output: "v0.1.0 c010 \nv0.1.5 c015 \n"),
        ]),
        'git rev-parse HEAD' => Process::result(output: "head\n"),
    ]);
    $source = new GitReleaseCommitSource;

    expect($source->versionHistory())->toHaveCount(2)
        ->and($source->versionHistory())->toHaveCount(2);

    $source->forgetHistory();

    expect($source->versionHistory())->toHaveCount(3);
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

it('reads the pull requests of a range from one topo-ordered git log', function (): void {
    Process::fake([
        'git log --topo-order *' => Process::result(output: implode('', [
            "pr1\x1fbase c1\x1fMerge pull request #1 from org/feat/widget\x1fWidget dashboard\x1e\n",
            "c1\x1fbase\x1fAdd widget\x1f\x1e\n",
        ])),
    ]);

    expect((new GitReleaseCommitSource)->changes('base', 'pr1'))->toBe([
        ['title' => 'Widget dashboard', 'branch' => 'feat/widget', 'number' => 1, 'details' => ['Add widget']],
    ]);

    Process::assertRan(fn ($process): bool => $process->command === 'git log --topo-order --format=%H%x1f%P%x1f%s%x1f%b%x1e base..pr1');
});
