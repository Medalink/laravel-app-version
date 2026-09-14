<?php

use Illuminate\Process\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Tests\Fixtures\FakeReleaseCommitSource;

function fakeFlatGit(): void
{
    Process::fake([
        'git rev-parse --short HEAD' => Process::result(output: 'abc1234'),
        'git rev-parse HEAD' => Process::result(output: 'head-commit'),
        'git diff-tree *' => Process::result(output: 'app/a.php'),
        'git log --format=%H -1 -- VERSION' => Process::result(output: 'version-file-sha'),
        'git rev-list --count *' => Process::result(output: '7'),
        'git log -1 --format=%cI HEAD' => Process::result(output: '2026-09-02T13:00:00-05:00'),
        'git log --format= --numstat*' => Process::result(output: "1000\t100\tapp/a.php\n"),
        '*' => Process::result(output: ''),
    ]);
}

beforeEach(function (): void {
    $this->writeVersionJson('0.2.0');
    $this->snapshotPath = $this->workspace.'/release-notes.json';
    $this->source = new FakeReleaseCommitSource;
    $this->source->history = [
        ['version' => '0.2.0', 'commit' => 'new-commit'],
        ['version' => '0.1.0', 'commit' => 'old-commit'],
    ];
    $this->source->subjects = [
        'old-commit..HEAD' => ['Fix manuscript selection'],
        'recent:old-commit' => ['Add manuscript editor'],
    ];
    $this->source->dates['old-commit'] = Carbon::parse('2026-08-01T12:00:00Z');
    $this->app->instance(ReleaseCommitSource::class, $this->source);
});

it('exports full history without a database and publishes it without Git', function (): void {
    $connection = config('database.default');
    config()->set('database.default', 'unavailable-at-build-time');

    $this->artisan('app:release-notes:backfill', ['--all' => true, '--output' => $this->snapshotPath])->assertSuccessful();

    config()->set('database.default', $connection);
    $snapshot = json_decode(File::get($this->snapshotPath), true);
    expect($snapshot['build'])->toBe(AppVersion::full())
        ->and($snapshot['releases'])->toHaveCount(2)
        ->and($snapshot['releases'][0]['published_at'])->toStartWith('2026-08-01')
        ->and(ReleaseNote::query()->count())->toBe(0);

    $this->app->instance(ReleaseCommitSource::class, Mockery::mock(ReleaseCommitSource::class));
    Process::preventStrayProcesses();
    $this->artisan('app:release-notes:publish', ['--from-file' => $this->snapshotPath])->assertSuccessful();

    $release = ReleaseNote::forVersion('0.2.0');
    expect(ReleaseNote::published())->toHaveCount(2)
        ->and($release->sections[ReleaseNote::SECTION_FIXED])->toBe(['Fixed manuscript selection.'])
        ->and($release->previous_version)->toBe('0.1.0')
        ->and($release->source_commit)->toBe('head-commit');

    $publishedAt = $release->published_at->toISOString();
    $this->travel(1)->day();
    $this->artisan('app:release-notes:publish', ['--from-file' => $this->snapshotPath])->assertSuccessful();
    expect(ReleaseNote::query()->count())->toBe(2)
        ->and(ReleaseNote::forVersion('0.2.0')->published_at->toISOString())->toBe($publishedAt);
});

it('keeps the previous snapshot intact when Git export fails', function (): void {
    File::put($this->snapshotPath, 'previous artifact');
    $this->source->throwOnSubjects = true;
    $this->artisan('app:release-notes:backfill', ['--output' => $this->snapshotPath])->assertFailed();
    expect(File::get($this->snapshotPath))->toBe('previous artifact');
});

it('rejects another build and malformed snapshots without publishing partial history', function (): void {
    $this->artisan('app:release-notes:backfill', ['--all' => true, '--output' => $this->snapshotPath])->assertSuccessful();
    $snapshot = json_decode(File::get($this->snapshotPath), true);
    $original = $snapshot;
    $snapshot['build'] = '0.2.0.9+different';
    File::put($this->snapshotPath, json_encode($snapshot));
    $this->artisan('app:release-notes:publish', ['--from-file' => $this->snapshotPath])->assertFailed();

    $original['releases'][1]['version'] = 'invalid';
    File::put($this->snapshotPath, json_encode($original));
    $this->artisan('app:release-notes:publish', ['--from-file' => $this->snapshotPath])->assertFailed();
    expect(ReleaseNote::query()->count())->toBe(0);
});

it('rejects a missing snapshot and conflicting export options', function (): void {
    $this->artisan('app:release-notes:publish', ['--from-file' => $this->snapshotPath])->assertFailed();
    $this->artisan('app:release-notes:backfill', ['--force' => true, '--output' => $this->snapshotPath])->assertFailed();
    expect(ReleaseNote::query()->count())->toBe(0);
});

it('generates one deterministic flat file locally and reads and publishes it without Git at runtime', function (): void {
    $this->writeVersionFile('0.2.0');
    fakeFlatGit();
    config()->set('app-version.flat', true);
    $connection = config('database.default');
    config()->set('database.default', 'unavailable-at-build-time');

    try {
        $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
        $first = File::get(AppVersion::flatPath());
        $snapshot = json_decode($first, true);
        $this->travel(1)->day();
        $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
        expect(File::get(AppVersion::flatPath()))->toBe($first)
            ->and($snapshot['source_commit'])->toBe('head-commit')
            ->and($snapshot['release_notes']['releases'])->toHaveCount(2);
    } finally {
        config()->set('database.default', $connection);
    }
    AppVersion::clearCache();
    $this->app->instance(ReleaseCommitSource::class, Mockery::mock(ReleaseCommitSource::class));
    Process::swap(new Factory);
    Process::preventStrayProcesses();
    expect(AppVersion::full())->toBe('0.2.0.7+abc1234')
        ->and(AppVersion::stats()['lifetime_additions'])->toBe(1000);
    $this->artisan('app:release-notes:publish', ['--from-file' => AppVersion::flatPath()])->assertSuccessful();
    expect(ReleaseNote::published())->toHaveCount(2)
        ->and(ReleaseNote::forVersion('0.2.0')->sections[ReleaseNote::SECTION_FIXED])->toBe(['Fixed manuscript selection.']);
    Process::assertNothingRan();
});

it('keeps the committed flat file stable after an artifact-only commit', function (): void {
    $this->writeVersionFile('0.2.0');
    fakeFlatGit();
    $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
    $first = File::get(AppVersion::flatPath());
    Process::fake([
        'git diff-tree --no-commit-id --name-only -r HEAD' => Process::result(output: 'version-info.json'),
        'git rev-parse HEAD~1' => Process::result(output: 'head-commit'),
    ]);
    Process::preventStrayProcesses();
    $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
    expect(File::get(AppVersion::flatPath()))->toBe($first);
});

it('preserves the last complete flat file when release collection fails', function (): void {
    $this->writeVersionFile('0.2.0');
    fakeFlatGit();
    File::put(AppVersion::flatPath(), 'previous artifact');
    $this->source->throwOnSubjects = true;
    expect(fn () => $this->artisan('app:version', ['--flat' => true])->run())->toThrow(RuntimeException::class);
    expect(File::get(AppVersion::flatPath()))->toBe('previous artifact');
});
