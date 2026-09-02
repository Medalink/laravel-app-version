<?php

use Illuminate\Support\Facades\DB;
use Medalink\AppVersion\Models\ReleaseNote;

it('orders published releases by semantic version, not insertion or string order', function (): void {
    ReleaseNote::factory()->create(['version' => '1.2.9']);
    ReleaseNote::factory()->create(['version' => '1.2.10']);
    ReleaseNote::factory()->create(['version' => '1.10.0']);

    expect(ReleaseNote::published()->pluck('version')->all())->toBe(['1.10.0', '1.2.10', '1.2.9'])
        ->and(ReleaseNote::latestPublished()?->version)->toBe('1.10.0')
        ->and(ReleaseNote::newerThan('1.2.9')->pluck('version')->all())->toBe(['1.10.0', '1.2.10'])
        ->and(ReleaseNote::newerThan(null)->count())->toBe(3);
});

it('serves published releases from cache until a release changes', function (): void {
    ReleaseNote::factory()->create(['version' => '1.0.0']);
    ReleaseNote::published();

    DB::enableQueryLog();
    ReleaseNote::published();
    ReleaseNote::latestPublished();
    expect(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();

    ReleaseNote::factory()->create(['version' => '1.1.0']);

    expect(ReleaseNote::latestPublished()?->version)->toBe('1.1.0');
});

it('resolves the current release from the running version, falling back to the latest', function (): void {
    ReleaseNote::factory()->create(['version' => '1.0.0', 'headline' => 'One']);
    ReleaseNote::factory()->create(['version' => '1.1.0', 'headline' => 'Two']);

    $this->writeVersionJson('1.0.0');
    expect(ReleaseNote::currentPublished()?->headline)->toBe('One');

    $this->writeVersionJson('1.2.0');
    expect(ReleaseNote::currentPublished()?->headline)->toBe('Two');
});

it('builds client payloads with compact or full sections', function (): void {
    $release = ReleaseNote::factory()->create([
        'version' => '1.0.0',
        'sections' => [ReleaseNote::SECTION_NEW => ['Full one.', 'Full two.']],
        'summary_sections' => [ReleaseNote::SECTION_NEW => ['Compact.']],
        'feature_groups' => [['title' => 'Editor', 'summary' => '', 'sections' => [ReleaseNote::SECTION_NEW => ['Full one.']], 'item_count' => 1]],
        'item_count' => 2,
    ]);

    $compact = $release->toFeedArray();
    $full = $release->toFeedArray(compact: false);

    expect($compact['sections'][ReleaseNote::SECTION_NEW])->toBe(['Compact.'])
        ->and($compact['sections'][ReleaseNote::SECTION_FIXED])->toBe([])
        ->and($compact['featureGroups'])->toBe([])
        ->and($full['sections'][ReleaseNote::SECTION_NEW])->toBe(['Full one.', 'Full two.'])
        ->and($full['featureGroups'][0]['title'])->toBe('Editor')
        ->and($full['publishedAt'])->toBeString();
});
