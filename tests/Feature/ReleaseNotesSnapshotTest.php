<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Tests\Fixtures\FakeReleaseCommitSource;

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
