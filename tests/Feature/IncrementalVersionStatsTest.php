<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * `app:version --stats-cache` must write exactly what the uncached command
 * writes. These tests build real Git histories, run both, compare the bytes,
 * and look at the Git commands the cached run made to prove it only read the
 * history it had not seen.
 */

beforeEach(function (): void {
    $this->cachePath = $this->workspace.'/cache/app-version-stats.json';
    statsCommandLog(clear: true);

    statsGit($this->workspace, 'init', '--quiet');
    statsGit($this->workspace, 'symbolic-ref', 'HEAD', 'refs/heads/main');
    statsGit($this->workspace, 'config', 'user.name', 'Package Tests');
    statsGit($this->workspace, 'config', 'user.email', 'tests@example.test');
    statsGit($this->workspace, 'config', 'commit.gpgsign', 'false');
    statsGit($this->workspace, 'config', 'tag.gpgsign', 'false');
    statsGit($this->workspace, 'config', 'core.autocrlf', 'false');
    // Generated files and scratch checkouts stay out of the history under test.
    File::put($this->workspace.'/.git/info/exclude', "/out/\n/cache/\n/release/\n/storage/\n/shallow/\n/worktree/\n/xdg/\n");
});

afterEach(function (): void {
    statsEnvironment('XDG_CONFIG_HOME', null);
});

/** Set (or with null, remove) a variable the Git processes inherit. */
function statsEnvironment(string $name, ?string $value): void
{
    // Symfony Process passes on what getenv() and $_SERVER both know.
    putenv($value === null ? $name : "{$name}={$value}");

    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
    } else {
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
}

/** Every process really runs; the fake only records what was started. */
function statsRecordProcesses(): void
{
    Process::fake(function (PendingProcess $pending) {
        $command = $pending->command;
        $process = is_array($command)
            ? new SymfonyProcess($command, $pending->path)
            : SymfonyProcess::fromShellCommandline($command, $pending->path);

        if ($pending->input !== null) {
            $process->setInput($pending->input);
        }

        $process->setTimeout(120)->run();
        statsCommandLog(is_array($command) ? implode(' ', $command) : $command);

        return Process::result($process->getOutput(), $process->getErrorOutput(), $process->getExitCode());
    });
}

/** @return list<string> the commands recorded since the last clear */
function statsCommandLog(?string $command = null, bool $clear = false): array
{
    static $commands = [];

    if ($clear) {
        $commands = [];
    }

    if ($command !== null) {
        $commands[] = $command;
    }

    return $commands;
}

function statsGit(string $workspace, string ...$arguments): string
{
    $process = new SymfonyProcess(['git', ...$arguments], $workspace);
    $process->setTimeout(60)->run();

    expect($process->isSuccessful())->toBeTrue('git '.implode(' ', $arguments).': '.$process->getErrorOutput());

    return trim($process->getOutput());
}

/** @param array<string, string|null> $files path => contents, null deletes */
function statsCommit(string $workspace, string $message, array $files = [], ?string $date = null): string
{
    foreach ($files as $path => $contents) {
        $target = $workspace.'/'.$path;

        if ($contents === null) {
            File::delete($target);
        } else {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $contents);
        }
    }

    statsGit($workspace, 'add', '--all');
    $commit = new SymfonyProcess(['git', 'commit', '--quiet', '--allow-empty', '-m', $message], $workspace, $date !== null
        ? ['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date]
        : null);
    $commit->setTimeout(60)->run();

    expect($commit->isSuccessful())->toBeTrue('git commit: '.$commit->getErrorOutput());

    return statsGit($workspace, 'rev-parse', 'HEAD');
}

function statsLines(int $count, string $word): string
{
    return implode('', array_map(static fn (int $line): string => "{$word} {$line}\n", range(1, $count)));
}

/** Point the command at another checkout (a clone or a linked worktree). */
function statsUseCheckout(string $path): void
{
    config()->set('app-version.repository_path', $path);
    config()->set('app-version.version_file', $path.'/VERSION');
}

/**
 * Runs app:version without and with the stats cache and requires identical
 * files; returns the Git commands the cached run made.
 *
 * @param  array<string, mixed>  $options
 * @return list<string>
 */
function statsCompare(array $options = ['--flat' => true]): array
{
    $test = test();
    $out = statsOut();
    statsRecordProcesses();

    $test->artisan('app:version', [...$options, '--output' => "{$out}/full.json"])->assertSuccessful();
    statsCommandLog(clear: true);
    $test->artisan('app:version', [...$options, '--stats-cache' => $test->cachePath, '--output' => "{$out}/cached.json"])->assertSuccessful();

    expect(File::get("{$out}/cached.json"))->toBe(File::get("{$out}/full.json"));

    return statsCommandLog();
}

/**
 * The revisions the cached run listed with `git log --numstat`.
 *
 * @param  list<string>  $commands
 * @return list<string>
 */
function statsNumstatReads(array $commands): array
{
    $reads = [];

    foreach ($commands as $command) {
        if (preg_match('/^git log --format=@%H %cI %P --numstat (.+) --$/', $command, $match) === 1) {
            $reads[] = $match[1];
        }
    }

    return $reads;
}

/**
 * Commands that walk the whole history on every run.
 *
 * @param  list<string>  $commands
 * @return list<string>
 */
function statsFullWalks(array $commands): array
{
    return array_values(array_filter($commands, static fn (string $command): bool => preg_match(
        '/^git (?:rev-list [0-9a-f]{40} --|rev-list --count HEAD|log --max-parents=0|log --format=%H -- VERSION|log --format= --numstat$)/',
        $command,
    ) === 1));
}

/** Where the compared files go: next to the cache, outside the history. */
function statsOut(): string
{
    return dirname(test()->cachePath, 2).'/out';
}

function statsJson(string $name = 'cached'): array
{
    return json_decode(File::get(statsOut()."/{$name}.json"), true);
}

it('matches the uncached output through merges, tags, a VERSION bump and rewritten history', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the manuscript editor', [
        'app/editor.php' => statsLines(40, 'editor'),
        'README.md' => "Sample\n",
    ]);
    $first = statsCommit($ws, 'Tighten the editor toolbar', ['app/editor.php' => statsLines(45, 'editor')]);

    // Cold cache: the whole history is read once.
    expect(statsNumstatReads(statsCompare()))->toBe([$first]);

    // Renames, a binary file, a deletion, and a merged branch.
    statsCommit($ws, 'Move the editor into its own folder', [
        'app/editor.php' => null,
        'app/Editor/Editor.php' => statsLines(44, 'editor').'moved line'."\n",
        'public/logo.png' => "\x89PNG\r\n\x1a\n\0\0\0binary",
    ]);
    statsGit($ws, 'checkout', '--quiet', '-b', 'feature');
    statsCommit($ws, 'feat: add manuscript comments', ['app/Comments.php' => statsLines(30, 'comment')]);
    statsCommit($ws, 'Fix comment ordering', ['app/Comments.php' => statsLines(28, 'comment')."sorted\n"]);
    statsGit($ws, 'checkout', '--quiet', 'main');
    statsCommit($ws, 'Remove the old readme', ['README.md' => null]);
    statsGit($ws, 'merge', '--quiet', '--no-ff', '-m', 'Merge branch feature', 'feature');
    $merged = statsGit($ws, 'rev-parse', 'HEAD');

    expect(statsNumstatReads(statsCompare()))->toBe(["{$merged} ^{$first}"])
        ->and(statsJson()['stats']['commit_additions'])->toBe(0);

    // An exact tag on the cached HEAD reads nothing but HEAD's own numstat.
    statsGit($ws, 'tag', '-a', 'v0.1.0', '-m', 'v0.1.0');

    expect(statsNumstatReads(statsCompare()))->toBe(["-1 {$merged}"])
        ->and(statsJson()['build'])->toBe(0);

    // A VERSION bump: the new boundary is summed from the commits just read.
    statsCommit($ws, 'Speed up autosave', ['app/Editor/Editor.php' => statsLines(50, 'editor')]);
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    $bumped = statsCommit($ws, 'feat: add export to PDF', ['app/Export.php' => statsLines(20, 'export')]);

    expect(statsNumstatReads(statsCompare()))->toBe(["{$bumped} ^{$merged}"])
        ->and(statsJson())->toMatchArray(['version' => '0.2.0', 'build' => 1]);

    // The boundary is cached now: the build range is a subtraction.
    $next = statsCommit($ws, 'Fix export margins', ['app/Export.php' => statsLines(21, 'export')]);

    expect(statsNumstatReads(statsCompare()))->toBe(["{$next} ^{$bumped}"])
        ->and(statsJson()['stats']['build_additions'])->toBeGreaterThan(0);

    // Rewrite the bump and what followed: the newest cached entry is no
    // longer an ancestor, the tagged merge is.
    statsGit($ws, 'reset', '--quiet', '--hard', 'HEAD~2');
    statsGit($ws, 'commit', '--quiet', '--amend', '-m', 'chore: Bump version to 0.2.0 again');
    $rewritten = statsCommit($ws, 'feat: add export to Word', ['app/Export.php' => statsLines(25, 'export')]);

    expect(statsNumstatReads(statsCompare()))->toBe(["{$rewritten} ^{$next}", "{$rewritten} ^{$merged}"])
        ->and(statsJson()['build'])->toBe(1);

    // An unrelated history (and an unreadable cache file) reads everything once.
    File::put($this->cachePath, '{not json');
    statsGit($ws, 'checkout', '--quiet', '--orphan', 'replacement');
    statsCommit($ws, 'feat: start over');
    $orphan = statsCommit($ws, 'Polish the new start', ['app/Export.php' => statsLines(26, 'export')]);

    expect(statsNumstatReads(statsCompare()))->toBe([$orphan])
        ->and(statsJson()['stats']['total_commits'])->toBe(2);

    // A cached history HEAD does not share is recognised from the listing alone.
    $again = statsCommit($ws, 'Polish it again', ['app/Export.php' => statsLines(27, 'export')]);
    $cache = json_decode(File::get($this->cachePath), true);
    $cache['commits'][$next] = ['additions' => 1, 'deletions' => 1, 'commits' => 99, 'used' => 1, 'first_commit_at' => '2020-01-01T00:00:00+00:00'];
    File::put($this->cachePath, json_encode($cache));

    expect(statsNumstatReads(statsCompare()))->toBe(["{$again} ^{$next}"]);
});

it('adds only the new commits to the nearest cached ancestor', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    $cached = statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    statsCompare([]);

    // Skew the cached sums: a cached run can only see them by building on them.
    $cache = json_decode(File::get($this->cachePath), true);
    $cache['commits'][$cached]['additions'] += 1000;
    File::put($this->cachePath, json_encode($cache));
    $head = statsCommit($ws, 'Grow the editor again', ['app/editor.php' => statsLines(18, 'editor')]);

    statsRecordProcesses();
    $this->artisan('app:version', ['--output' => $ws.'/out/full.json'])->assertSuccessful();
    statsCommandLog(clear: true);
    $this->artisan('app:version', ['--stats-cache' => $this->cachePath, '--output' => $ws.'/out/cached.json'])->assertSuccessful();

    expect(statsNumstatReads(statsCommandLog()))->toBe(["{$head} ^{$cached}"])
        ->and(statsJson()['stats']['lifetime_additions'])->toBe(statsJson('full')['stats']['lifetime_additions'] + 1000);
});

it('reads only the commits since the last run, with no walk over the whole history', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    $released = statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    statsGit($ws, 'tag', '-a', 'v0.1.0', '-m', 'v0.1.0');
    statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    $deployed = statsCommit($ws, 'feat: add comments', ['app/comments.php' => statsLines(10, 'comment')]);
    statsCompare();

    // The next deploy: a merged pull request that leaves VERSION alone.
    statsGit($ws, 'checkout', '--quiet', '-b', 'feature');
    statsCommit($ws, 'Fix comment spacing', ['app/comments.php' => statsLines(11, 'comment')]);
    statsGit($ws, 'checkout', '--quiet', 'main');
    statsGit($ws, 'merge', '--quiet', '--no-ff', '-m', 'Merge pull request #2 from feature', 'feature');
    $head = statsGit($ws, 'rev-parse', 'HEAD');

    $commands = statsCompare();

    expect($commands)->toBe([
        'git log -1 --format=%H%n%h%n%cI HEAD --',
        'git --version',
        'git var -l',
        'git rev-parse --git-path info/attributes --git-path shallow --git-path info/grafts --show-toplevel',
        'git ls-files -s -- :(glob)**/.gitattributes',
        'git for-each-ref --format=%(refname) %(refname:short) %(objectname) %(*objectname) %(*objecttype) refs/tags refs/replace',
        'git describe --tags --match v[0-9]*.[0-9]*.[0-9]* --abbrev=0',
        "git log --format=@%H %cI %P --numstat {$head} ^{$deployed} --",
        'git diff-tree --stdin -r --name-only -- VERSION',
        "git log --format=%s {$released}..HEAD",
    ])->and(statsFullWalks($commands))->toBe([])
        ->and(statsJson())->toMatchArray(['version' => '0.2.0', 'build' => 3])
        ->and(collect(statsJson()['release_notes']['releases'])->pluck('version')->all())->toBe(['0.1.0', '0.2.0']);
});

it('reads the VERSION history again when the first-parent chain changes the file', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    statsCompare();

    // A branch bumps VERSION and is merged straight onto the cached HEAD: the
    // merge's first parent is the cached commit, and it differs from it.
    statsGit($ws, 'checkout', '--quiet', '-b', 'release');
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    statsCommit($ws, 'feat: add export', ['app/export.php' => statsLines(10, 'export')]);
    statsGit($ws, 'checkout', '--quiet', 'main');
    statsGit($ws, 'merge', '--quiet', '--no-ff', '-m', 'Merge branch release', 'release');

    $commands = statsCompare();

    expect($commands)->toContain('git log --format=%H -- VERSION')
        ->and(statsJson())->toMatchArray(['version' => '0.2.0', 'build' => 2]);

    // On the next deploy the new listing is cached and VERSION is unchanged.
    statsCommit($ws, 'Fix export margins', ['app/export.php' => statsLines(11, 'export')]);

    expect(statsCompare())->not->toContain('git log --format=%H -- VERSION');
});

it('keeps the VERSION history exact when a merged side branch bumps the version', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add a', ['app/a.php' => statsLines(10, 'a')]);
    statsCommit($ws, 'Grow a', ['app/a.php' => statsLines(20, 'a')]);
    statsCompare();

    statsGit($ws, 'checkout', '--quiet', '-b', 'feature');
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    statsCommit($ws, 'feat: add x', ['app/x.php' => statsLines(10, 'x')]);
    statsGit($ws, 'checkout', '--quiet', 'main');
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'Main work', ['app/m.php' => statsLines(13, 'm')]);
    statsCompare();
    statsGit($ws, 'merge', '--quiet', '--no-ff', '-m', 'Merge feature', 'feature');
    statsCompare();
    statsCommit($ws, 'After merge', ['app/n.php' => statsLines(3, 'n')]);
    statsCompare();

    expect(statsJson()['version'])->toBe('0.2.0');
});

it('matches when tags appear below, move behind, or are backfilled into a cached history', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    $c0 = statsCommit($ws, 'feat: add the editor', ['app/a.php' => statsLines(10, 'a')]);
    $c1 = statsCommit($ws, 'Grow a', ['app/a.php' => statsLines(20, 'a')]);
    statsCommit($ws, 'Grow a more', ['app/a.php' => statsLines(25, 'a')]);
    $c3 = statsCommit($ws, 'Add b', ['app/b.php' => statsLines(7, 'b')]);
    statsCompare();

    // A tag on an older commit the cache never saw as a boundary.
    statsGit($ws, 'tag', 'v0.1.0', $c1);

    expect(statsCompare())->toContain("git rev-list --boundary {$c1}..{$c3} --");
    // Now its sums are cached: a subtraction.
    expect(statsCompare())->not->toContain("git rev-list --boundary {$c1}..{$c3} --");

    // The tag moves behind the cached boundary.
    statsCommit($ws, 'Add c', ['app/c.php' => statsLines(9, 'c')]);
    statsGit($ws, 'tag', '-d', 'v0.1.0');
    statsGit($ws, 'tag', '-a', 'v0.1.0', '-m', 'moved', $c0);
    statsCompare();

    // Versions bumped in VERSION, then a tag backfilled between them and moved.
    $mid = statsCommit($ws, 'Fix a crash', ['app/a.php' => statsLines(11, 'a')]);
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    statsCommit($ws, 'feat: add d', ['app/d.php' => statsLines(10, 'd')]);
    $this->writeVersionFile('0.3.0');
    statsCommit($ws, 'chore: Bump version to 0.3.0');
    statsCommit($ws, 'feat: add e', ['app/e.php' => statsLines(10, 'e')]);
    statsCompare();
    statsGit($ws, 'tag', 'v0.1.5', $mid);
    statsCompare();
    statsGit($ws, 'tag', '-f', 'v0.1.5', 'HEAD~2');
    statsCompare();

    expect(collect(statsJson()['release_notes']['releases'])->pluck('version')->all())->toBe(['0.1.0', '0.1.5', '0.2.0', '0.3.0']);
});

it('matches for a lightweight release tag on HEAD after an annotated one', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    statsGit($ws, 'tag', '-a', 'v0.1.0', '-m', 'v0.1.0');
    statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    statsCompare();

    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    // Sorted last among the refs, with empty peeled fields.
    statsGit($ws, 'tag', 'v0.2.0');

    expect(statsCompare())->toContain('git describe --tags --match v[0-9]*.[0-9]*.[0-9]* --abbrev=0 HEAD~1')
        ->and(statsJson())->toMatchArray(['version' => '0.2.0', 'build' => 0])
        ->and(statsJson()['stats']['build_additions'])->toBeGreaterThan(0);
});

it('matches after a shallow clone is deepened', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');

    foreach (range(1, 8) as $n) {
        statsCommit($ws, "Change {$n}", ['app/a.php' => statsLines(10 + $n * 3, 'a'), "app/f{$n}.php" => statsLines($n, 'f')]);
    }

    $shallow = $ws.'/shallow';
    $url = str_replace('\\', '/', $ws);
    statsGit($ws, 'clone', '--quiet', '--depth', '3', 'file://'.(str_starts_with($url, '/') ? '' : '/').$url, $shallow);
    statsGit($shallow, 'config', 'core.autocrlf', 'false');
    File::put($shallow.'/.git/info/exclude', "/out/\n");
    statsUseCheckout($shallow);

    statsCompare();
    expect(statsJson()['stats']['total_commits'])->toBe(3);

    statsGit($shallow, 'fetch', '--quiet', '--deepen', '2');
    statsCompare();
    expect(statsJson()['stats']['total_commits'])->toBe(5);

    statsGit($shallow, 'fetch', '--quiet', '--unshallow');
    statsCompare();
    expect(statsJson()['stats']['total_commits'])->toBe(8);
});

it('discards cached sums when the diff configuration changes what numstat reports', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(60, 'editor')]);
    statsCommit($ws, 'Move the editor', ['app/editor.php' => null, 'app/screen.php' => statsLines(60, 'editor')."moved\n"]);
    statsCompare([]);
    $withRenames = statsJson()['stats']['lifetime_additions'];

    statsGit($ws, 'config', 'diff.renames', 'false');
    $head = statsGit($ws, 'rev-parse', 'HEAD');

    expect(statsNumstatReads(statsCompare([])))->toBe([$head])
        ->and(statsJson()['stats']['lifetime_additions'])->not->toBe($withRenames);
});

it('discards cached sums when an attributes file Git reads changes', function (Closure $change, ?Closure $arrange = null): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(60, 'editor')]);
    statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(80, 'editor')]);
    if ($arrange !== null) {
        $arrange($ws);
    }

    statsCompare([]);
    $before = statsJson()['stats']['lifetime_additions'];

    $change($ws);
    $reads = statsCompare([]);

    expect(statsJson()['stats']['lifetime_additions'])->not->toBe($before)
        ->and(statsNumstatReads($reads))->toHaveCount(1)
        ->and(statsNumstatReads($reads)[0])->not->toContain('^');
})->with([
    // Closure values reach the test as they are (the parameters are Closure).
    'the common info/attributes of a linked worktree' => [
        function (string $ws): void {
            File::put($ws.'/.git/info/attributes', "*.php binary\n");
        },
        function (string $ws): void {
            statsGit($ws, 'worktree', 'add', '--quiet', '--detach', $ws.'/worktree', 'HEAD');
            statsUseCheckout($ws.'/worktree');
        },
    ],
    'an untracked top-level .gitattributes file' => [
        function (string $ws): void {
            File::put($ws.'/.gitattributes', "*.php binary\n");
        },
    ],
    'a nested .gitattributes file' => [
        function (string $ws): void {
            statsCommit($ws, 'Treat the application code as binary', ['app/.gitattributes' => "*.php binary\n"]);
        },
    ],
    'the global attributes file' => [
        function (string $ws): void {
            File::put($ws.'/xdg/git/attributes', "*.php binary\n");
        },
        function (string $ws): void {
            File::ensureDirectoryExists($ws.'/xdg/git');
            File::put($ws.'/xdg/git/attributes', "*.md binary\n");
            statsEnvironment('XDG_CONFIG_HOME', $ws.'/xdg');
        },
    ],
    'core.bigFileThreshold' => [
        function (string $ws): void {
            statsGit($ws, 'config', 'core.bigFileThreshold', '100');
        },
    ],
]);

it('takes the earliest root when an unrelated history is merged in', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')], '2024-05-01T10:00:00+00:00');
    statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')], '2024-05-02T10:00:00+00:00');
    statsCompare();

    statsGit($ws, 'checkout', '--quiet', '--orphan', 'imported');
    statsGit($ws, 'rm', '--quiet', '-r', '--cached', '.');
    File::deleteDirectory($ws.'/app');
    statsCommit($ws, 'Import the legacy tools', ['tools/legacy.php' => statsLines(5, 'legacy'), 'VERSION' => "0.1.0\n"], '2019-03-01T08:00:00+00:00');
    statsGit($ws, 'checkout', '--quiet', '-f', 'main');
    statsGit($ws, 'merge', '--quiet', '--no-ff', '--allow-unrelated-histories', '-X', 'ours', '-m', 'Merge the legacy tools', 'imported');

    statsCompare();

    // Newer Git writes the UTC offset as "Z".
    expect(statsJson()['first_commit_at'])->toStartWith('2019-03-01T08:00:00');
});

it('builds on an older cached ancestor after a rollback', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    $older = statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    statsCompare();
    $rolledBack = statsCommit($ws, 'Grow the editor again', ['app/editor.php' => statsLines(18, 'editor')]);
    $newest = statsCommit($ws, 'Grow the editor once more', ['app/editor.php' => statsLines(20, 'editor')]);
    statsCompare();

    // Deploying an older commit: HEAD is an ancestor of the newest entry.
    statsGit($ws, 'reset', '--quiet', '--hard', $rolledBack);

    expect(statsNumstatReads(statsCompare()))->toBe(["{$rolledBack} ^{$newest}", "{$rolledBack} ^{$older}"]);
});

it('reuses earlier releases and compiles only the running version', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    statsCommit($ws, 'feat: add comments', ['app/comments.php' => statsLines(10, 'comment')]);
    statsCompare();

    $cache = json_decode(File::get($this->cachePath), true);
    expect($cache['releases'])->toHaveCount(1)
        ->and(array_values($cache['releases'])[0]['version'])->toBe('0.1.0');

    // A marked entry shows the cached payload is the one written.
    $key = array_key_first($cache['releases']);
    $cache['releases'][$key]['headline'] = 'From the cache';
    File::put($this->cachePath, json_encode($cache));
    statsCommit($ws, 'Fix comment spacing', ['app/comments.php' => statsLines(11, 'comment')]);

    $this->artisan('app:version', ['--flat' => true, '--stats-cache' => $this->cachePath, '--output' => $ws.'/out/cached.json'])->assertSuccessful();
    $releases = collect(statsJson()['release_notes']['releases'])->keyBy('version');

    expect($releases['0.1.0']['headline'])->toBe('From the cache')
        ->and($releases['0.2.0']['sections']['fixed'])->toContain('Fixed comment spacing.');

    // Other release-notes configuration compiles every release again.
    config()->set('app-version.release_notes.voice', 'imperative');
    statsCompare();

    expect(collect(statsJson()['release_notes']['releases'])->firstWhere('version', '0.1.0')['headline'])->not->toBe('From the cache');
});

it('falls back to the uncached collection unless metadata must be complete', function (): void {
    $this->writeVersionFile('1.5.0');
    Process::fake([
        'git log -1 --format=%H%n%h%n%cI HEAD --' => Process::result(output: str_repeat('a', 40)."\naaaaaaa\n2026-01-01T00:00:00+00:00\n"),
        'git rev-parse --git-path*' => Process::result(output: ".git/info/attributes\n.git/shallow\n.git/info/grafts\n"),
        'git --version' => Process::result(output: 'git version 2.47.0'),
        'git log --format=@*' => Process::result(errorOutput: 'fatal: bad object', exitCode: 128),
        'git rev-parse --short HEAD' => Process::result(output: 'abc1234'),
        'git log --format=%H -1 -- VERSION' => Process::result(output: 'version-file-sha'),
        'git rev-list --count *' => Process::result(output: '7'),
        'git log --format= --numstat -1 HEAD' => Process::result(output: "10\t2\tapp/a.php\n"),
        'git log --format= --numstat version-file-sha..HEAD' => Process::result(output: "50\t5\tapp/a.php\n"),
        'git log --format= --numstat' => Process::result(output: "1000\t100\tapp/a.php\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('app:version', ['--stats-cache' => $this->cachePath])->assertSuccessful();

    expect(json_decode(File::get($this->workspace.'/storage/version.json'), true))->toMatchArray([
        'commit' => 'abc1234',
        'build' => 7,
    ])->and(json_decode(File::get($this->workspace.'/storage/version.json'), true)['stats'])->toMatchArray([
        'build_additions' => 50,
        'lifetime_additions' => 1000,
        'commit_additions' => 10,
    ])->and(File::exists($this->cachePath))->toBeFalse();

    expect(fn () => $this->artisan('app:version', ['--stats-cache' => $this->cachePath, '--strict' => true])->run())
        ->toThrow(RuntimeException::class, 'Unable to collect version statistics');
});

it('writes an explicit --output even when HEAD only records the committed snapshot', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
    statsCommit($ws, 'chore: record version snapshot');

    $this->artisan('app:version', ['--flat' => true, '--output' => $ws.'/release/version-info.json'])->assertSuccessful();

    expect(json_decode(File::get($ws.'/release/version-info.json'), true)['source_commit'])
        ->toBe(statsGit($ws, 'rev-parse', 'HEAD'));
});

it('requires a path for --output and --stats-cache', function (string $option): void {
    $this->writeVersionFile('0.1.0');

    $this->artisan('app:version', [$option => ''])->assertFailed();
})->with(['--output', '--stats-cache']);
