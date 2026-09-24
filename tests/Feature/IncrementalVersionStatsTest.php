<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * `app:version --stats-cache` must write exactly what the uncached command
 * writes. These tests build real Git histories, run both, compare the bytes,
 * and look at the `git log --numstat` calls the cached run made to prove it
 * only read the history it had not seen.
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
    // Generated files stay out of the history under test.
    File::put($this->workspace.'/.git/info/exclude', "/out/\n/cache/\n/release/\n/storage/\n");
});

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
function statsCommit(string $workspace, string $message, array $files = []): string
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
    statsGit($workspace, 'commit', '--quiet', '--allow-empty', '-m', $message);

    return statsGit($workspace, 'rev-parse', 'HEAD');
}

function statsWorkspace(): string
{
    return (string) config('app-version.repository_path');
}

function statsLines(int $count, string $word): string
{
    return implode('', array_map(static fn (int $line): string => "{$word} {$line}\n", range(1, $count)));
}

/**
 * Runs app:version without and with the stats cache and requires identical
 * files; returns the revisions the cached run passed to `git log --numstat`.
 *
 * @param  array<string, mixed>  $options
 * @return list<string>
 */
function statsCompare(array $options = ['--flat' => true]): array
{
    $test = test();
    $full = statsWorkspace().'/out/full.json';
    $cached = statsWorkspace().'/out/cached.json';
    statsRecordProcesses();

    $test->artisan('app:version', [...$options, '--output' => $full])->assertSuccessful();
    statsCommandLog(clear: true);
    $test->artisan('app:version', [...$options, '--stats-cache' => $test->cachePath, '--output' => $cached])->assertSuccessful();

    expect(File::get($cached))->toBe(File::get($full));

    return statsNumstatReads(statsCommandLog());
}

/** @return list<string> */
function statsNumstatReads(array $commands): array
{
    $reads = [];

    foreach ($commands as $command) {
        if (preg_match('/^git log --format=%H --numstat (.+) --$/', $command, $match) === 1) {
            $reads[] = $match[1];
        }
    }

    return $reads;
}

function statsJson(string $name = 'cached'): array
{
    return json_decode(File::get(statsWorkspace()."/out/{$name}.json"), true);
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
    expect(statsCompare())->toBe([$first]);

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

    expect(statsCompare())->toBe(["{$first}..{$merged}"])
        ->and(statsJson()['stats']['commit_additions'])->toBe(0);

    // An exact tag on the cached HEAD reads nothing but HEAD's own numstat.
    statsGit($ws, 'tag', '-a', 'v0.1.0', '-m', 'v0.1.0');

    expect(statsCompare())->toBe(["-1 {$merged}"])
        ->and(statsJson()['build'])->toBe(0);

    // A VERSION bump: the new boundary is summed from the commits just read.
    statsCommit($ws, 'Speed up autosave', ['app/Editor/Editor.php' => statsLines(50, 'editor')]);
    $this->writeVersionFile('0.2.0');
    statsCommit($ws, 'chore: Bump version to 0.2.0');
    $bumped = statsCommit($ws, 'feat: add export to PDF', ['app/Export.php' => statsLines(20, 'export')]);

    expect(statsCompare())->toBe(["{$merged}..{$bumped}"])
        ->and(statsJson())->toMatchArray(['version' => '0.2.0', 'build' => 1]);

    // The boundary is cached now: the build range is a subtraction.
    $next = statsCommit($ws, 'Fix export margins', ['app/Export.php' => statsLines(21, 'export')]);

    expect(statsCompare())->toBe(["{$bumped}..{$next}"])
        ->and(statsJson()['stats']['build_additions'])->toBeGreaterThan(0);

    // Rewrite the bump and what followed: the newest cached ancestor is the tag.
    statsGit($ws, 'reset', '--quiet', '--hard', 'HEAD~2');
    statsGit($ws, 'commit', '--quiet', '--amend', '-m', 'chore: Bump version to 0.2.0 again');
    $rewritten = statsCommit($ws, 'feat: add export to Word', ['app/Export.php' => statsLines(25, 'export')]);

    expect(statsCompare())->toBe(["{$merged}..{$rewritten}"])
        ->and(statsJson()['build'])->toBe(1);

    // An unrelated history (and an unreadable cache file) reads everything once.
    File::put($this->cachePath, '{not json');
    statsGit($ws, 'checkout', '--quiet', '--orphan', 'replacement');
    statsCommit($ws, 'feat: start over');
    $orphan = statsCommit($ws, 'Polish the new start', ['app/Export.php' => statsLines(26, 'export')]);

    expect(statsCompare())->toBe([$orphan])
        ->and(statsJson()['stats']['total_commits'])->toBe(2);
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

    expect(statsNumstatReads(statsCommandLog()))->toBe(["{$cached}..{$head}"])
        ->and(statsJson()['stats']['lifetime_additions'])->toBe(statsJson('full')['stats']['lifetime_additions'] + 1000);
});

it('reads the whole history again when cached counts do not add up', function (): void {
    $ws = $this->workspace;
    $this->writeVersionFile('0.1.0');
    statsCommit($ws, 'feat: add the editor', ['app/editor.php' => statsLines(10, 'editor')]);
    $cached = statsCommit($ws, 'Grow the editor', ['app/editor.php' => statsLines(15, 'editor')]);
    statsCompare([]);

    // As if the entry were made in a shallower clone of the same history.
    $cache = json_decode(File::get($this->cachePath), true);
    $cache['commits'][$cached]['commits'] -= 1;
    File::put($this->cachePath, json_encode($cache));
    $head = statsCommit($ws, 'Grow the editor again', ['app/editor.php' => statsLines(18, 'editor')]);

    expect(statsCompare([]))->toBe(["{$cached}..{$head}", $head])
        ->and(json_decode(File::get($this->cachePath), true)['commits'][$head]['commits'])->toBe(3);
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

    expect(statsCompare([]))->toBe([$head])
        ->and(statsJson()['stats']['lifetime_additions'])->not->toBe($withRenames);
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
        'git rev-parse --short HEAD' => Process::result(output: 'abc1234'),
        '*rev-parse*' => Process::result(output: str_repeat('a', 40)),
        '*--version*' => Process::result(output: 'git version 2.47.0'),
        'git log --format=%H -1 -- VERSION' => Process::result(output: 'version-file-sha'),
        'git rev-list --count *' => Process::result(output: '7'),
        '*rev-list*' => Process::result(errorOutput: 'fatal: bad object', exitCode: 128),
        'git log --format= --numstat -1 HEAD' => Process::result(output: "10\t2\tapp/a.php\n"),
        'git log --format= --numstat version-file-sha..HEAD' => Process::result(output: "50\t5\tapp/a.php\n"),
        'git log --format= --numstat' => Process::result(output: "1000\t100\tapp/a.php\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('app:version', ['--stats-cache' => $this->cachePath])->assertSuccessful();

    expect(json_decode(File::get($this->workspace.'/storage/version.json'), true)['stats'])->toMatchArray([
        'build_additions' => 50,
        'lifetime_additions' => 1000,
        'commit_additions' => 10,
    ]);

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
