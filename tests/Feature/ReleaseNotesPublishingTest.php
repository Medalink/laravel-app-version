<?php

use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Tests\Fixtures\FakeReleaseCommitSource;
use Medalink\AppVersion\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->source = new FakeReleaseCommitSource;
    $this->app->instance(ReleaseCommitSource::class, $this->source);
});

it('publishes and upserts release notes for the current version', function (): void {
    $this->writeVersionJson('3.6.0');
    $this->source->history = [
        ['version' => '3.6.0', 'commit' => 'ccc333'],
        ['version' => '3.5.0', 'commit' => 'bbb222'],
    ];
    $this->source->subjects['bbb222..HEAD'] = [
        'Add incident watchboard and monitoring controls',
        'Fix uptime display conflict',
    ];

    $this->artisan('app:release-notes:publish')->assertSuccessful();
    $this->artisan('app:release-notes:publish')->assertSuccessful();

    expect(ReleaseNote::query()->where('version', '3.6.0')->count())->toBe(1);

    $release = ReleaseNote::forVersion('3.6.0');

    expect($release?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_PARSED)
        ->and($release?->previous_version)->toBe('3.5.0')
        ->and($release?->source_range)->toBe('bbb222..HEAD')
        ->and($release?->source_commit)->toBe('head-commit')
        ->and($release?->item_count)->toBe(2);
});

it('stores fallback release notes when parsing yields nothing useful', function (): void {
    $this->writeVersionJson('3.6.1');
    $this->source->history = [
        ['version' => '3.6.1', 'commit' => 'ddd444'],
        ['version' => '3.6.0', 'commit' => 'ccc333'],
    ];
    $this->source->subjects['ccc333..HEAD'] = [
        'Merge pull request #2 from Medalink/dependabot/npm_and_yarn/rollup-4.59.0',
        'Fix flaky search filter test',
    ];

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    expect(ReleaseNote::forVersion('3.6.1')?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_FALLBACK)
        ->and(ReleaseNote::forVersion('3.6.1')?->item_count)->toBeGreaterThan(0);
});

it('merges parsed commits under configured custom release notes', function (): void {
    $this->writeVersionJson('9.9.9');
    config()->set('app-version.release_notes.custom_releases', [
        '9.9.9' => [
            'headline' => 'Packet capture is now built in',
            'summary' => 'This release adds live capture.',
            'sections' => [
                ReleaseNote::SECTION_NEW => ['Added live capture.'],
                ReleaseNote::SECTION_IMPROVED => [],
                ReleaseNote::SECTION_FIXED => ['Improved capture performance.'],
            ],
        ],
    ]);
    $this->source->history = [
        ['version' => '9.9.9', 'commit' => 'ddd444'],
        ['version' => '9.9.8', 'commit' => 'ccc333'],
    ];
    $this->source->subjects['ccc333..HEAD'] = ['Fix capture upload retry state'];

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    $release = ReleaseNote::forVersion('9.9.9');

    expect($release?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_CUSTOM_PARSED)
        ->and($release?->headline)->toBe('Packet capture is now built in')
        ->and($release?->sections[ReleaseNote::SECTION_FIXED])->toBe([
            'Improved capture performance.',
            'Fixed capture upload retry state.',
        ])
        ->and($release?->item_count)->toBe(3);
});

it('keeps curated summary sections separate from merged full sections', function (): void {
    $this->writeVersionJson('9.9.7');
    config()->set('app-version.release_notes.custom_releases', [
        '9.9.7' => [
            'headline' => 'Curated',
            'summary' => 'Curated summary.',
            'sections' => [
                ReleaseNote::SECTION_NEW => ['Added full detail one.', 'Added full detail two.'],
            ],
            'summary_sections' => [
                ReleaseNote::SECTION_NEW => ['Added compact detail.'],
            ],
        ],
    ]);
    $this->source->history = [
        ['version' => '9.9.7', 'commit' => 'ddd444'],
        ['version' => '9.9.6', 'commit' => 'ccc333'],
    ];

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    $release = ReleaseNote::forVersion('9.9.7');

    expect($release?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_CUSTOM)
        ->and($release?->sections[ReleaseNote::SECTION_NEW])->toHaveCount(2)
        ->and($release?->summary_sections[ReleaseNote::SECTION_NEW])->toBe(['Added compact detail.']);
});

it('can republish the current release from an older base version', function (): void {
    $this->writeVersionJson('2.0.2');
    $this->source->history = [
        ['version' => '2.0.2', 'commit' => 'ccc333'],
        ['version' => '2.0.1', 'commit' => 'bbb222'],
        ['version' => '2.0.0', 'commit' => 'aaa111'],
    ];
    $this->source->subjects['aaa111..HEAD'] = [
        'Add incident watchboard and monitoring controls',
        'Improve release notes modal',
    ];

    $this->artisan('app:release-notes:publish', ['--from-version' => '2.0.0'])->assertSuccessful();

    $release = ReleaseNote::forVersion('2.0.2');

    expect($release?->previous_version)->toBe('2.0.0')
        ->and($release?->item_count)->toBe(2)
        ->and($release?->summary)->toContain('1 new feature')
        ->and($release?->summary)->toContain('1 improvement');
});

it('uses the previous version boundary instead of a stored release row commit', function (): void {
    $this->writeVersionJson('2.0.2');
    ReleaseNote::factory()->create(['version' => '2.0.1', 'source_commit' => 'late-201']);
    $this->source->history = [
        ['version' => '2.0.2', 'commit' => 'ccc333'],
        ['version' => '2.0.1', 'commit' => 'bbb222'],
    ];
    $this->source->subjects['bbb222..HEAD'] = ['Add one', 'Improve two'];
    $this->source->subjects['late-201..HEAD'] = ['Improve two'];

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    expect(ReleaseNote::forVersion('2.0.2')?->source_range)->toBe('bbb222..HEAD')
        ->and(ReleaseNote::forVersion('2.0.2')?->item_count)->toBe(2);
});

it('falls back to a tag or stored commit when git has no VERSION boundary for the previous version', function (): void {
    $this->writeVersionJson('2.0.3');
    ReleaseNote::factory()->create(['version' => '2.0.2', 'source_commit' => 'stored-202']);
    $this->source->history = [['version' => '2.0.3', 'commit' => 'ddd444']];
    $this->source->subjects['stored-202..HEAD'] = ['Fix one'];

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    expect(ReleaseNote::forVersion('2.0.3')?->previous_version)->toBe('2.0.2')
        ->and(ReleaseNote::forVersion('2.0.3')?->source_range)->toBe('stored-202..HEAD');
});

it('gives the oldest version no previous boundary even when newer releases are stored', function (): void {
    $this->writeVersionJson('0.1.0');
    ReleaseNote::factory()->create(['version' => '0.1.0', 'source_commit' => 'head-commit']);
    $this->source->history = [
        ['version' => '0.1.0', 'commit' => 'head-commit'],
        ['version' => '0.0.1', 'commit' => 'first-tag'],
    ];
    $this->source->subjects['recent:first-tag'] = ['Add the foundation', 'Add the first pages'];

    $this->artisan('app:release-notes:backfill', ['--from' => '0.0.1', '--to' => '0.0.1', '--force' => true])
        ->assertSuccessful();

    $release = ReleaseNote::forVersion('0.0.1');

    expect($release?->previous_version)->toBeNull()
        ->and($release?->source_range)->toBe('first-tag')
        ->and($release?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_PARSED)
        ->and($release?->item_count)->toBe(2);
});

it('does not block deployment on commit lookup failures unless strict', function (): void {
    $this->writeVersionJson('2.0.4');
    $this->source->history = [['version' => '2.0.4', 'commit' => 'ddd444']];
    $this->source->throwOnSubjects = true;

    $this->artisan('app:release-notes:publish')->assertSuccessful();

    expect(ReleaseNote::forVersion('2.0.4')?->generation_mode)->toBe(ReleaseNote::GENERATION_MODE_FALLBACK)
        ->and(ReleaseNote::forVersion('2.0.4')?->generation_warnings)->toContain('Commit lookup failed: simulated commit lookup failure');

    $this->artisan('app:release-notes:publish', ['--strict' => true])->assertFailed();
});

it('backfills historical releases without touching user read state', function (): void {
    $this->writeVersionJson('3.6.0');
    $this->source->history = [
        ['version' => '3.6.0', 'commit' => 'ccc333'],
        ['version' => '3.5.0', 'commit' => 'bbb222'],
        ['version' => '3.4.0', 'commit' => 'aaa111'],
    ];
    $this->source->subjects['aaa111..bbb222'] = ['Add richer health details'];
    $this->source->subjects['bbb222..HEAD'] = ['Fix stale session behavior'];

    $user = User::make();
    $user->markReleaseNotesRead('3.4.0');
    $before = $user->releaseNoteReadState();

    $this->artisan('app:release-notes:backfill', ['--latest' => 2])->assertSuccessful();

    expect(ReleaseNote::query()->count())->toBe(0);

    $this->artisan('app:release-notes:backfill', ['--latest' => 2, '--force' => true])->assertSuccessful();

    expect(ReleaseNote::published()->pluck('version')->all())->toBe(['3.6.0', '3.5.0'])
        ->and($user->fresh()?->releaseNoteReadState())->toEqual($before);
});

it('backfills an explicit version range', function (): void {
    $this->writeVersionJson('3.6.0');
    $this->source->history = [
        ['version' => '3.6.0', 'commit' => 'ccc333'],
        ['version' => '3.5.0', 'commit' => 'bbb222'],
        ['version' => '3.4.0', 'commit' => 'aaa111'],
        ['version' => '3.3.0', 'commit' => '999000'],
    ];

    $this->artisan('app:release-notes:backfill', ['--from' => '3.4.0', '--to' => '3.5.0', '--force' => true])
        ->assertSuccessful();

    expect(ReleaseNote::published()->pluck('version')->all())->toBe(['3.5.0', '3.4.0']);
});
