<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;

/**
 * @param  array<string, string>  $overrides  pattern => stdout
 */
function fakeGit(array $overrides = []): void
{
    Process::fake(array_map(
        static fn (string $output) => Process::result(output: $output),
        array_merge([
            'git rev-parse --short HEAD' => 'abc1234',
            'git log --format=%H -1 -- VERSION' => 'version-file-sha',
            'git tag --points-at HEAD*' => '',
            'git describe * --abbrev=0 HEAD~1' => '',
            'git describe * --abbrev=0' => '',
            'git rev-list --count HEAD' => '120',
            'git rev-list --count *' => '7',
            'git log --format= --numstat -1 HEAD' => "10\t2\tapp/a.php\n-\t-\tpublic/logo.png\n",
            'git log --format= --numstat version-file-sha..HEAD' => "50\t5\tapp/a.php\n",
            'git log --format= --numstat v1.4.0..HEAD' => "60\t6\tapp/a.php\n",
            'git log --format= --numstat v1.4.0..HEAD' => "60\t6\tapp/a.php\n",
            'git log --format= --numstat v1.3.0..HEAD' => "80\t8\tapp/a.php\n",
            'git log --format= --numstat' => "1000\t100\tapp/a.php\n",
        ], $overrides),
    ));
}

function generatedJson(): array
{
    return json_decode((string) File::get(AppVersion::jsonPath()), true);
}

it('fails without a VERSION file', function (): void {
    fakeGit();

    $this->artisan('app:version')->assertFailed();
});

it('rejects a malformed VERSION file', function (): void {
    fakeGit();
    $this->writeVersionFile('1.2');

    $this->artisan('app:version')->assertFailed();
});

it('counts builds from the last VERSION change when there are no tags', function (): void {
    fakeGit();
    $this->writeVersionFile('1.5.0');

    $this->artisan('app:version')->assertSuccessful();

    expect(generatedJson())->toMatchArray([
        'version' => '1.5.0',
        'build' => 7,
        'commit' => 'abc1234',
        'full' => '1.5.0.7+abc1234',
    ])->and(generatedJson()['stats'])->toBe([
        'total_commits' => 120,
        'commit_additions' => 10,
        'commit_deletions' => 2,
        'build_additions' => 50,
        'build_deletions' => 5,
        'lifetime_additions' => 1000,
        'lifetime_deletions' => 100,
    ]);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'git rev-list --count version-file-sha..HEAD');
});

it('reports build zero with empty build stats when VERSION was never committed and no tag exists', function (): void {
    fakeGit(['git log --format=%H -1 -- VERSION' => '']);
    $this->writeVersionFile('0.1.0');

    $this->artisan('app:version')->assertSuccessful();

    expect(generatedJson())->toMatchArray(['version' => '0.1.0', 'build' => 0, 'full' => '0.1.0.0+abc1234'])
        ->and(generatedJson()['stats'])->toMatchArray([
            'total_commits' => 120,
            'build_additions' => 0,
            'build_deletions' => 0,
            'lifetime_additions' => 1000,
        ]);

    Process::assertNotRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'git log --format= --numstat HEAD'));
});

it('uses an exact semver tag on HEAD as build zero and ranges stats from the previous tag', function (): void {
    fakeGit([
        'git tag --points-at HEAD*' => "v1.4.0\n",
        'git describe * --abbrev=0 HEAD~1' => "v1.3.0\n",
    ]);
    $this->writeVersionFile('1.2.0');

    $this->artisan('app:version')->assertSuccessful();

    expect(generatedJson())->toMatchArray(['version' => '1.4.0', 'build' => 0, 'full' => '1.4.0.0+abc1234'])
        ->and(generatedJson()['stats']['build_additions'])->toBe(80);
});

it('keeps counting builds from the newest reachable tag', function (): void {
    fakeGit(['git describe * --abbrev=0' => "v1.4.0\n"]);
    $this->writeVersionFile('1.4.0');

    $this->artisan('app:version')->assertSuccessful();

    expect(generatedJson())->toMatchArray(['version' => '1.4.0', 'build' => 7])
        ->and(generatedJson()['stats']['build_additions'])->toBe(60);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'git rev-list --count v1.4.0..HEAD');
});

it('prefers VERSION when it is ahead of the newest reachable tag', function (): void {
    fakeGit(['git describe * --abbrev=0' => "v1.4.0\n"]);
    $this->writeVersionFile('1.5.0');

    $this->artisan('app:version')->assertSuccessful();

    expect(generatedJson())->toMatchArray(['version' => '1.5.0', 'build' => 7])
        ->and(generatedJson()['stats']['build_additions'])->toBe(50);
});

it('refreshes the in-process reader after writing', function (): void {
    $this->writeVersionJson('0.0.1');
    fakeGit();
    $this->writeVersionFile('1.5.0');

    expect(AppVersion::version())->toBe('0.0.1');

    $this->artisan('app:version')->assertSuccessful();

    expect(AppVersion::version())->toBe('1.5.0');
});
