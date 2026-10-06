<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesCompiler;

function change(string $title, ?string $branch = null, int $commits = 1, ?int $number = null): array
{
    return [
        'title' => $title,
        'branch' => $branch,
        'number' => $number,
        'details' => array_fill(0, $commits, 'commit'),
    ];
}

beforeEach(function (): void {
    config()->set('app-version.release_notes.rollup_keys', ['/\bplan (\d+)\b/i', '/^[a-z]+\/(\d{3})-/']);
    config()->set('app-version.release_notes.feature_groups', [
        ['title' => 'Maps', 'patterns' => ['/\bmap\b/i']],
        ['title' => 'Billing', 'patterns' => ['/\binvoices?\b/i']],
    ]);
});

it('types pull requests by branch prefix and drops ignored branches', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compileChanges([
        change('Invoice exports', 'feat/invoice-exports'),
        change('Rounding on invoice totals', 'fix/invoice-rounding'),
        change('Faster invoice list', 'perf/invoice-list'),
        change('Plan the invoice rewrite', 'docs/invoice-plan'),
        change('Bump guzzle', 'dependabot/composer/guzzle'),
    ]);

    expect($compiled['sections'])->toBe([
        ReleaseNote::SECTION_NEW => ['Invoice exports.'],
        ReleaseNote::SECTION_IMPROVED => ['Faster invoice list.'],
        ReleaseNote::SECTION_FIXED => ['Rounding on invoice totals.'],
    ])->and($compiled['item_count'])->toBe(3);
});

it('rolls pull requests sharing a key into their biggest new feature', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compileChanges([
        change('Plan 120 phase 2: lightning on the map', 'feat/120-lightning', 4, 31),
        change('Radar map loop (plan 120)', 'feat/radar-loop', 9, 30),
        change('Fix radar tiles drawing off screen', 'fix/120-radar-tiles', 12, 32),
    ]);

    expect($compiled['item_count'])->toBe(1)
        ->and($compiled['feature_groups'][0]['changes'])->toBe([[
            'text' => 'Radar map loop (plan 120).',
            'section' => ReleaseNote::SECTION_NEW,
            'details' => ['Plan 120 phase 2: lightning on the map.', 'Fixed radar tiles drawing off screen.'],
            'commits' => 25,
            'refs' => [30, 31, 32],
        ]]);
});

it('ranks areas and their changes by size and sums up the release by area', function (): void {
    config()->set('app-version.release_notes.limits.highlights_per_group', 1);

    $compiled = app(ReleaseNotesCompiler::class)->compileChanges([
        change('Invoice exports', 'feat/invoice-exports', 2),
        change('Map clustering', 'feat/map-clustering', 3),
        change('Map legend spacing', 'fix/map-legend', 20),
        change('Settings page copy', 'fix/settings-copy'),
    ]);

    expect(collect($compiled['feature_groups'])->pluck('title')->all())->toBe(['Maps', 'Billing', ReleaseNotesCompiler::GENERAL_GROUP_TITLE])
        ->and(collect($compiled['feature_groups'][0]['changes'])->pluck('text')->all())->toBe(['Map legend spacing.', 'Map clustering.'])
        ->and($compiled['feature_groups'][0]['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Map legend spacing.'])
        ->and($compiled['feature_groups'][0]['commit_count'])->toBe(23)
        ->and($compiled['headline'])->toBe('New features and fixes in Maps and Billing')
        ->and($compiled['summary'])->toBe('This release brings 3 major updates, plus 1 smaller improvement or fix.');
});

it('places a change by its lead before the members it rolled up', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compileChanges([
        change('Invoice exports (plan 7)', 'feat/invoice-exports', 5),
        change('Show the map of customers (plan 7)', 'fix/customer-map', 1),
    ]);

    expect(collect($compiled['feature_groups'])->pluck('title')->all())->toBe(['Billing']);
});

it('falls back when no pull request survives filtering', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compileChanges([
        change('Rewrite the docs', 'docs/rewrite'),
    ]);

    expect($compiled['generation_mode'])->toBe(ReleaseNote::GENERATION_MODE_FALLBACK);
});
