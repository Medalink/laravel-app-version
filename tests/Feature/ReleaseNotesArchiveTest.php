<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesArchive;

function archive(): ReleaseNotesArchive
{
    return app(ReleaseNotesArchive::class);
}

it('expands the newest releases in full and collapses the rest into digests, newest version first', function (): void {
    foreach (['0.0.1', '0.0.2', '0.0.3', '0.0.10', '0.1.0'] as $version) {
        ReleaseNote::factory()->create([
            'version' => $version,
            'headline' => "Release {$version}",
            'sections' => [ReleaseNote::SECTION_FIXED => ["Fixed {$version}."]],
            'item_count' => 1,
        ]);
    }

    $described = archive()->describe();
    $digest = $described['olderReleases'][0];

    expect($described['expandedLimit'])->toBe(ReleaseNotesArchive::DEFAULT_EXPANDED_RELEASES)
        ->and($described['total'])->toBe(5)
        ->and(array_column($described['releases'], 'version'))->toBe(['0.1.0', '0.0.10'])
        ->and($described['releases'][0]['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Fixed 0.1.0.'])
        ->and($described['releases'][0]['featureGroups'][0]['title'])->toBe('Release Updates')
        ->and(array_column($described['olderReleases'], 'version'))->toBe(['0.0.3', '0.0.2', '0.0.1'])
        ->and($digest)->toHaveKeys(['version', 'previousVersion', 'headline', 'summary', 'publishedAt', 'itemCount', 'generationMode'])
        ->and(array_key_exists('sections', $digest))->toBeFalse()
        ->and(array_key_exists('featureGroups', $digest))->toBeFalse()
        ->and($digest['headline'])->toBe('Release 0.0.3')
        ->and($digest['itemCount'])->toBe(1)
        ->and(archive()->expanded()->pluck('version')->all())->toBe(['0.1.0', '0.0.10'])
        ->and(archive()->older()->pluck('version')->all())->toBe(['0.0.3', '0.0.2', '0.0.1']);
});

it('honours the configured expanded limit, including collapsing everything', function (): void {
    ReleaseNote::factory()->create(['version' => '1.0.0']);
    ReleaseNote::factory()->create(['version' => '1.1.0']);
    ReleaseNote::factory()->create(['version' => '1.2.0']);

    config()->set('app-version.release_notes.limits.archive_expanded_releases', 1);
    expect(archive()->expanded()->pluck('version')->all())->toBe(['1.2.0'])
        ->and(archive()->older()->pluck('version')->all())->toBe(['1.1.0', '1.0.0']);

    config()->set('app-version.release_notes.limits.archive_expanded_releases', 0);
    $described = archive()->describe();

    expect($described['expandedLimit'])->toBe(0)
        ->and($described['releases'])->toBe([])
        ->and(array_column($described['olderReleases'], 'version'))->toBe(['1.2.0', '1.1.0', '1.0.0']);
});

it('describes an empty archive before anything is published', function (): void {
    expect(archive()->describe())->toBe([
        'releases' => [],
        'olderReleases' => [],
        'total' => 0,
        'expandedLimit' => ReleaseNotesArchive::DEFAULT_EXPANDED_RELEASES,
    ]);
});

it('finds a collapsed release body by version', function (): void {
    ReleaseNote::factory()->create([
        'version' => '0.0.3',
        'sections' => [ReleaseNote::SECTION_FIXED => ['Fixed the old thing.']],
        'item_count' => 1,
    ]);

    expect(archive()->find('0.0.3')?->toFeedArray(compact: false)['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Fixed the old thing.'])
        ->and(archive()->find('9.9.9'))->toBeNull();
});
