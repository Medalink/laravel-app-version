<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\ReleaseNotes\SecurityClassifier;

it('rates security fixes by what their text names', function (string $text, string $section, string $area, ?string $severity): void {
    expect((new SecurityClassifier)->classify($text, [], $section, $area))->toBe($severity);
})->with([
    'advisory' => ['Bumped laravel/mcp to 1.0.1 (OAuth redirect advisory).', ReleaseNote::SECTION_FIXED, 'Security', SecurityClassifier::HIGH],
    'cve' => ['Upgraded libxml to close CVE-2026-1234.', ReleaseNote::SECTION_IMPROVED, 'General', SecurityClassifier::HIGH],
    'redaction' => ['Redact credential prompts from terminus.log.', ReleaseNote::SECTION_FIXED, 'Operations', SecurityClassifier::MEDIUM],
    'confinement' => ['Confine tusd hook paths to the configured upload directory.', ReleaseNote::SECTION_FIXED, 'Security', SecurityClassifier::MEDIUM],
    'ownership' => ['Verified ownership before decrypting SSH key metadata.', ReleaseNote::SECTION_FIXED, 'Security', SecurityClassifier::MEDIUM],
    'security area fix' => ['Gate ENUM appends behind --allow-unsafe-migration.', ReleaseNote::SECTION_FIXED, 'Security & Permissions', SecurityClassifier::LOW],
    'new feature' => ['Ai account with time-boxed root and recorded sessions, security logged.', ReleaseNote::SECTION_NEW, 'Security', null],
    'root-cause' => ['Recorded UPS power state so root-cause checks can fire.', ReleaseNote::SECTION_FIXED, 'Power', null],
    'permissions page' => ['Showed Impact Map permissions on the Admin Permissions page.', ReleaseNote::SECTION_IMPROVED, 'Impact Map', null],
    'excluded medium' => ['Escaped colour codes in the chart legend.', ReleaseNote::SECTION_FIXED, 'Dashboards', null],
    'ordinary fix' => ['Lock hosts in id order before bulk host updates.', ReleaseNote::SECTION_FIXED, 'Database', null],
]);

it('reads details as well as the headline', function (): void {
    expect((new SecurityClassifier)->classify('Tightened the upload hook.', ['Closed a path traversal in the hook.'], ReleaseNote::SECTION_FIXED))
        ->toBe(SecurityClassifier::HIGH);
});

it('keeps defaults for every rule the config leaves out', function (): void {
    config()->set('app-version.release_notes.security', ['sections' => [ReleaseNote::SECTION_FIXED]]);

    $classifier = SecurityClassifier::fromConfig();

    expect($classifier->classify('Redact secrets from logs.', [], ReleaseNote::SECTION_FIXED))->toBe(SecurityClassifier::MEDIUM)
        ->and($classifier->classify('Redact secrets from logs.', [], ReleaseNote::SECTION_IMPROVED))->toBeNull();
});
