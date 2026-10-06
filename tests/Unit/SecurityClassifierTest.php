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
    'missing authorization' => ['Authorize HostDetail config reads and reject foreign config IDs.', ReleaseNote::SECTION_IMPROVED, 'Dashboards', SecurityClassifier::HIGH],
    'authorize writes' => ['Authorize SiteIncidents site-monitor toggles.', ReleaseNote::SECTION_IMPROVED, 'Incidents', SecurityClassifier::HIGH],
    'required permission' => ['Required manage_incidents and clamped days for Node Manager history backfill.', ReleaseNote::SECTION_FIXED, 'Alerting', SecurityClassifier::HIGH],
    'login exposure' => ['Embed the stored UPS login only for ping_hosts users.', ReleaseNote::SECTION_FIXED, 'UPS', SecurityClassifier::HIGH],
    'permission gate' => ['PCAP permissions: gate PcapContextTab on view_pcap.', ReleaseNote::SECTION_IMPROVED, 'PCAP', SecurityClassifier::MEDIUM],
    'mutation gates' => ['Enforce service catalog and role mutation gates.', ReleaseNote::SECTION_FIXED, 'PCAP', SecurityClassifier::MEDIUM],
    'supply chain' => ['Harden prod supply chain: APT, NodeSource, Go toolchain.', ReleaseNote::SECTION_FIXED, 'Operations', SecurityClassifier::MEDIUM],
    'unprivileged' => ['Switched Terminus to unprivileged ICMP sockets.', ReleaseNote::SECTION_FIXED, 'Operations', SecurityClassifier::MEDIUM],
    'production debug' => ['Published Blaze config and cast debug to bool to prevent production leak.', ReleaseNote::SECTION_FIXED, 'Interface', SecurityClassifier::MEDIUM],
    'channel isolation' => ["Kept each ping session's broadcast on its own channel.", ReleaseNote::SECTION_FIXED, 'Incidents', SecurityClassifier::MEDIUM],
    'release notes title' => ['Redesign release notes: version rail, one row per change, security fixes.', ReleaseNote::SECTION_IMPROVED, 'Release Notes', null],
    'double-encoded text' => ['Resolved double-encoded ampersands in site names.', ReleaseNote::SECTION_FIXED, 'Incidents', null],
    'credential fallback' => ['Fixed missing credential fallback in UPS battery replacement.', ReleaseNote::SECTION_FIXED, 'UPS', null],
    'certificate correctness' => ["IRIS_TLS=provided serves the operator's certificate and never replaces it.", ReleaseNote::SECTION_FIXED, 'Operations', null],
    'hardened plumbing' => ['Hardened Redis stream consumers.', ReleaseNote::SECTION_FIXED, 'Operations', null],
    'permission name' => ['Fix Commands navigation: use correct permission name create_batch.', ReleaseNote::SECTION_FIXED, 'Interface', null],
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
