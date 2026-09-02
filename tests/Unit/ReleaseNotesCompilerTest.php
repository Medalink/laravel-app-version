<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesCompiler;

it('classifies and rewrites commit subjects into user-facing sections', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compile([
        'Add incident watchboard and monitoring controls',
        'Standardize shared table sorting actions',
        'Fix connected devices clickable',
        'Merge pull request #2 from Medalink/dependabot/npm_and_yarn/rollup-4.59.0',
        'Add AGENTS.md with learned preferences and workspace facts',
    ]);

    expect($compiled['generation_mode'])->toBe(ReleaseNote::GENERATION_MODE_PARSED)
        ->and($compiled['sections'][ReleaseNote::SECTION_NEW])->toBe(['Added incident watchboard and monitoring controls.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe(['Shared table sorting actions are now more consistent.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Connected devices clickable now works correctly.'])
        ->and($compiled['headline'])->toBe('New features and fixes are live')
        ->and($compiled['summary'])->toBe('This release includes 1 new feature, 1 improvement, 1 fix.');
});

it('applies cleanup replacements before detail rewrites', function (): void {
    config()->set('app-version.release_notes.cleanup_replacements', ['/\bNNM\b/i' => 'Node Manager']);
    config()->set('app-version.release_notes.detail_rewrites', [
        '/\bmissing IP in Node Manager status check\b/i' => 'Node Manager status checks now retain the target IP',
    ]);

    $compiled = app(ReleaseNotesCompiler::class)->compile([
        'Fix missing IP in NNM status check',
    ]);

    expect($compiled['sections'][ReleaseNote::SECTION_FIXED])
        ->toBe(['Node Manager status checks now retain the target IP.']);
});

it('falls back to the app-named headline when nothing user-facing survives filtering', function (): void {
    $compiled = app(ReleaseNotesCompiler::class)->compile([
        'Merge pull request #2 from Medalink/dependabot/npm_and_yarn/rollup-4.59.0',
        'Fix flaky search filter test',
        'Add MySQL schema dump for reference',
    ]);

    expect($compiled['generation_mode'])->toBe(ReleaseNote::GENERATION_MODE_FALLBACK)
        ->and($compiled['headline'])->toBe('Sample has been updated')
        ->and($compiled['item_count'])->toBe(1);
});

it('honours a configured fallback headline', function (): void {
    config()->set('app-version.release_notes.fallback.headline', 'Fresh paint');

    expect(app(ReleaseNotesCompiler::class)->fallback()['headline'])->toBe('Fresh paint');
});

it('groups items by configured feature areas with a general bucket for the rest', function (): void {
    config()->set('app-version.release_notes.feature_groups', [
        ['title' => 'Editor', 'summary' => 'Writing surface.', 'patterns' => ['/\beditor\b/i']],
        ['title' => 'Billing', 'summary' => 'Plans and payments.', 'patterns' => ['/\b(billing|invoice)\b/i']],
    ]);

    $compiled = app(ReleaseNotesCompiler::class)->compile([
        'Add editor focus mode',
        'Fix invoice totals',
        'Improve onboarding copy',
    ]);

    expect(collect($compiled['feature_groups'])->pluck('title')->all())
        ->toBe(['Editor', 'Billing', ReleaseNotesCompiler::GENERAL_GROUP_TITLE])
        ->and($compiled['feature_groups'][0]['sections'][ReleaseNote::SECTION_NEW])->toBe(['Added editor focus mode.'])
        ->and($compiled['feature_groups'][2]['item_count'])->toBe(1);
});

it('keeps full sections while deriving capped summary sections', function (): void {
    config()->set('app-version.release_notes.limits.per_section', 2);

    $compiled = app(ReleaseNotesCompiler::class)->compile(['Fix one', 'Fix two', 'Fix three']);

    expect($compiled['sections'][ReleaseNote::SECTION_FIXED])->toHaveCount(3)
        ->and($compiled['summary_sections'][ReleaseNote::SECTION_FIXED])->toHaveCount(2)
        ->and($compiled['item_count'])->toBe(3);
});
