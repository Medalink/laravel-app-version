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

it('builds a digest without sections or feature groups and labels every section', function (): void {
    $release = ReleaseNote::factory()->create([
        'version' => '1.0.0',
        'previous_version' => '0.9.0',
        'headline' => 'Digest me',
        'sections' => [ReleaseNote::SECTION_NEW => ['Full one.']],
        'item_count' => 1,
    ]);

    $digest = $release->toDigestArray();

    expect($digest)->toHaveKeys(['version', 'previousVersion', 'headline', 'summary', 'publishedAt', 'itemCount', 'generationMode'])
        ->and(array_key_exists('sections', $digest))->toBeFalse()
        ->and(array_key_exists('featureGroups', $digest))->toBeFalse()
        ->and($digest['version'])->toBe('1.0.0')
        ->and($digest['previousVersion'])->toBe('0.9.0')
        ->and($digest['headline'])->toBe('Digest me')
        ->and($digest['itemCount'])->toBe(1)
        ->and($digest['publishedAt'])->toBeString()
        ->and(array_keys(ReleaseNote::SECTION_LABELS))->toBe(ReleaseNote::SECTIONS)
        ->and(ReleaseNote::SECTION_LABELS[ReleaseNote::SECTION_IMPROVED])->toBe('Improved');
});

it('reads highlights from ranked feature groups, capped per group and per modal', function (): void {
    config()->set('app-version.release_notes.limits.highlights_per_group', 1);
    config()->set('app-version.release_notes.limits.modal_groups', 2);

    $groups = collect(['Maps', 'Billing', 'Settings'])->map(fn (string $title): array => [
        'title' => $title,
        'summary' => '',
        'sections' => ReleaseNote::emptySections(),
        'item_count' => 2,
        'changes' => [
            ['text' => "{$title} one.", 'section' => ReleaseNote::SECTION_NEW, 'details' => [], 'commits' => 2, 'refs' => []],
            ['text' => "{$title} two.", 'section' => ReleaseNote::SECTION_FIXED, 'details' => [], 'commits' => 1, 'refs' => []],
        ],
    ])->all();
    $release = ReleaseNote::factory()->create(['version' => '2.0.0', 'feature_groups' => $groups]);

    expect($release->highlights())->toBe([
        ['title' => 'Maps', 'short' => 'Maps', 'items' => ['Maps one.'], 'more' => 1],
        ['title' => 'Billing', 'short' => 'Billing', 'items' => ['Billing one.'], 'more' => 1],
    ])->and($release->hiddenHighlightGroupCount())->toBe(1)
        ->and($release->summaryItemCount())->toBe(2)
        ->and($release->toFeedArray()['hiddenHighlightGroups'])->toBe(1);
});

it('has no highlights for a release compiled from commits', function (): void {
    $release = ReleaseNote::factory()->create(['version' => '2.0.1']);

    expect($release->highlights())->toBe([])
        ->and($release->toFeedArray()['highlights'])->toBe([]);
});

it('lists every change of a pull request release with its area and security rating', function (): void {
    $release = ReleaseNote::factory()->create([
        'version' => '2.1.0',
        'feature_groups' => [
            ['title' => 'Operations', 'summary' => '', 'sections' => [], 'changes' => [
                ['text' => 'Deploy rework.', 'section' => ReleaseNote::SECTION_NEW, 'details' => ['Faster swaps.'], 'commits' => 40, 'refs' => [681]],
                ['text' => 'Redact credential prompts from logs.', 'section' => ReleaseNote::SECTION_FIXED, 'details' => [], 'commits' => 2, 'refs' => [702]],
            ]],
            ['title' => 'Security', 'summary' => '', 'sections' => [], 'changes' => [
                ['text' => 'Bumped laravel/mcp (OAuth redirect advisory).', 'section' => ReleaseNote::SECTION_FIXED, 'details' => [], 'commits' => 1, 'refs' => [690]],
            ]],
        ],
    ]);

    expect($release->changes())->toHaveCount(3)
        ->and($release->changes()[0])->toBe([
            'area' => 'Operations', 'text' => 'Deploy rework.', 'section' => ReleaseNote::SECTION_NEW,
            'details' => ['Faster swaps.'], 'commits' => 40, 'refs' => [681], 'security' => null,
        ])
        ->and(array_column($release->securityChanges(), 'text'))->toBe([
            'Bumped laravel/mcp (OAuth redirect advisory).',
            'Redact credential prompts from logs.',
        ])
        ->and(array_column($release->securityChanges(), 'security'))->toBe(['high', 'medium']);
});

it('turns the section lines of a commit release into changes without sizes', function (): void {
    $release = ReleaseNote::factory()->create([
        'version' => '2.0.1',
        'feature_groups' => [['title' => 'Editor', 'summary' => '', 'sections' => [
            ReleaseNote::SECTION_NEW => ['Added tabs.'],
            ReleaseNote::SECTION_FIXED => ['Escaped user names to stop XSS.'],
        ], 'item_count' => 2]],
    ]);

    expect(array_column($release->changes(), 'text'))->toBe(['Added tabs.', 'Escaped user names to stop XSS.'])
        ->and(array_column($release->changes(), 'commits'))->toBe([null, null])
        ->and(array_column($release->changes(), 'security'))->toBe([null, 'high']);
});
