<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesCompiler;

function compileNotes(array $subjects): array
{
    return app(ReleaseNotesCompiler::class)->compile($subjects);
}

it('classifies by leading verb and renders in past tense', function (): void {
    $compiled = compileNotes([
        'Add incident watchboard and monitoring controls',
        'Standardize shared table sorting actions',
        'Fix connected devices clickable',
        'Merge pull request #2 from Medalink/dependabot/npm_and_yarn/rollup-4.59.0',
        'Add AGENTS.md with learned preferences and workspace facts',
    ]);

    expect($compiled['generation_mode'])->toBe(ReleaseNote::GENERATION_MODE_PARSED)
        ->and($compiled['sections'][ReleaseNote::SECTION_NEW])->toBe(['Added incident watchboard and monitoring controls.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe(['Standardized shared table sorting actions.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Fixed connected devices clickable.'])
        ->and($compiled['headline'])->toBe('New features and fixes are live')
        ->and($compiled['summary'])->toBe('This release includes 1 new feature, 1 improvement, 1 fix.');
});

it('past-tenses verbs after conjunctions and leaves unknown leading words alone', function (): void {
    $compiled = compileNotes([
        'Pin the version badge to the sidebar footer and link it to a version history page',
        'Stable global rail, account menu, and link-blue text links',
        'Drop the build footer, keep the rail, and show the badge',
    ]);

    expect($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe([
        'Pinned the version badge to the sidebar footer and linked it to a version history page.',
        'Stable global rail, account menu, and link-blue text links.',
        'Dropped the build footer, kept the rail, and showed the badge.',
    ]);
});

it('keeps subjects as written in imperative voice', function (): void {
    config()->set('app-version.release_notes.voice', ReleaseNotesCompiler::VOICE_IMPERATIVE);

    $compiled = compileNotes(['Pin the version badge to the sidebar footer']);

    expect($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe(['Pin the version badge to the sidebar footer.']);
});

it('conjugates regular and irregular verbs', function (): void {
    expect(ReleaseNotesCompiler::pastTense('stop'))->toBe('stopped')
        ->and(ReleaseNotesCompiler::pastTense('open'))->toBe('opened')
        ->and(ReleaseNotesCompiler::pastTense('narrow'))->toBe('narrowed')
        ->and(ReleaseNotesCompiler::pastTense('apply'))->toBe('applied')
        ->and(ReleaseNotesCompiler::pastTense('size'))->toBe('sized')
        ->and(ReleaseNotesCompiler::pastTense('make'))->toBe('made')
        ->and(ReleaseNotesCompiler::pastTense('keep'))->toBe('kept')
        ->and(ReleaseNotesCompiler::pastTense('prefer'))->toBe('preferred')
        ->and(ReleaseNotesCompiler::pastTense('wait'))->toBe('waited')
        ->and(ReleaseNotesCompiler::pastTense('pin'))->toBe('pinned')
        ->and(ReleaseNotesCompiler::pastTense('added'))->toBe('added');
});

it('honours conventional commit types, scopes, and breaking markers', function (): void {
    $compiled = compileNotes([
        'feat(editor): distraction-free mode for long drafts',
        'fix: stale permission banner on project pages',
        'perf(search): cache the catalog lookups',
        'refactor!: drop the legacy export format',
    ]);

    expect($compiled['sections'][ReleaseNote::SECTION_NEW])->toBe(['Distraction-free mode for long drafts.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_FIXED])->toBe(['Stale permission banner on project pages.'])
        ->and($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe([
            'Cached the catalog lookups.',
            ReleaseNotesCompiler::BREAKING_PREFIX.'Dropped the legacy export format.',
        ]);
});

it('treats fix signals and fixing verbs as fixes even without a fix verb up front', function (): void {
    $compiled = compileNotes([
        'Make pm2 see artisan crashes and wait for services before booting workers',
        'Stop the collaboration connection flap',
        'Keep settings buttons on one line',
        'Narrow undefined regex matches so the strict build passes',
    ]);

    expect($compiled['sections'][ReleaseNote::SECTION_FIXED])->toBe([
        'Made pm2 see artisan crashes and waited for services before booting workers.',
        'Stopped the collaboration connection flap.',
        'Narrowed undefined regex matches so the strict build passes.',
    ])->and($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe(['Kept settings buttons on one line.']);
});

it('strips pull request refs, ticket keys, gitmoji, and backticks, and de-duplicates', function (): void {
    $compiled = compileNotes([
        'Give native select carets inset right padding (#272)',
        'PUB-142: Colour `card` titles as links',
        ':sparkles: Add a version badge',
        'Add a version badge (#280)',
        'add a version badge.',
    ]);

    expect($compiled['sections'][ReleaseNote::SECTION_IMPROVED])->toBe([
        'Gave native select carets inset right padding.',
        'Coloured card titles as links.',
    ])->and($compiled['sections'][ReleaseNote::SECTION_NEW])->toBe(['Added a version badge.'])
        ->and($compiled['item_count'])->toBe(3);
});

it('applies cleanup replacements before detail rewrites', function (): void {
    config()->set('app-version.release_notes.cleanup_replacements', ['/\bNNM\b/i' => 'Node Manager']);
    config()->set('app-version.release_notes.detail_rewrites', [
        '/\bmissing IP in Node Manager status check\b/i' => 'Node Manager status checks now retain the target IP',
    ]);

    $compiled = compileNotes(['Fix missing IP in NNM status check']);

    expect($compiled['sections'][ReleaseNote::SECTION_FIXED])
        ->toBe(['Node Manager status checks now retain the target IP.']);
});

it('falls back to the app-named headline when nothing user-facing survives filtering', function (): void {
    $compiled = compileNotes([
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

it('groups by feature area matching the raw subject as well as the rewritten sentence', function (): void {
    config()->set('app-version.release_notes.cleanup_replacements', ['/\bRLS\b/' => 'data isolation']);
    config()->set('app-version.release_notes.feature_groups', [
        ['title' => 'Editor', 'summary' => 'Writing surface.', 'patterns' => ['/\beditor\b/i']],
        ['title' => 'Security', 'summary' => 'Isolation and access.', 'patterns' => ['/\bRLS\b/']],
    ]);

    $compiled = compileNotes([
        'Add editor focus mode',
        'Tighten RLS on invitation rows',
        'Improve onboarding copy',
    ]);

    expect(collect($compiled['feature_groups'])->pluck('title')->all())
        ->toBe(['Editor', 'Security', ReleaseNotesCompiler::GENERAL_GROUP_TITLE])
        ->and($compiled['feature_groups'][1]['sections'][ReleaseNote::SECTION_IMPROVED])->toBe(['Tightened data isolation on invitation rows.'])
        ->and($compiled['feature_groups'][2]['item_count'])->toBe(1);
});

it('adds configured section verbs on top of the built-in lexicon', function (): void {
    config()->set('app-version.release_notes.section_prefixes', [ReleaseNote::SECTION_NEW => ['unveil']]);

    $compiled = compileNotes(['Unveil the new dashboard', 'Add a widget']);

    expect($compiled['sections'][ReleaseNote::SECTION_NEW])->toBe(['Unveiled the new dashboard.', 'Added a widget.']);
});

it('keeps full sections while deriving capped summary sections', function (): void {
    config()->set('app-version.release_notes.limits.per_section', 2);

    $compiled = compileNotes(['Fix one', 'Fix two', 'Fix three']);

    expect($compiled['sections'][ReleaseNote::SECTION_FIXED])->toHaveCount(3)
        ->and($compiled['summary_sections'][ReleaseNote::SECTION_FIXED])->toHaveCount(2)
        ->and($compiled['item_count'])->toBe(3);
});
