<?php

use Medalink\AppVersion\Support\SemanticVersion;

it('validates X.Y.Z versions only', function (): void {
    expect(SemanticVersion::isValid('1.2.3'))->toBeTrue()
        ->and(SemanticVersion::isValid('v1.2.3'))->toBeFalse()
        ->and(SemanticVersion::isValid('1.2'))->toBeFalse()
        ->and(SemanticVersion::isValid(null))->toBeFalse();
});

it('treats anything as newer than nothing and compares numerically', function (): void {
    expect(SemanticVersion::isNewer('1.2.10', '1.2.9'))->toBeTrue()
        ->and(SemanticVersion::isNewer('1.2.9', '1.2.10'))->toBeFalse()
        ->and(SemanticVersion::isNewer('1.0.0', '1.0.0'))->toBeFalse()
        ->and(SemanticVersion::isNewer('0.0.1', null))->toBeTrue();
});

it('round-trips tags through the configured prefix', function (): void {
    expect(SemanticVersion::tag('2.0.0'))->toBe('v2.0.0')
        ->and(SemanticVersion::fromTag('v2.0.0'))->toBe('2.0.0')
        ->and(SemanticVersion::tagGlob())->toBe('v[0-9]*.[0-9]*.[0-9]*');

    config()->set('app-version.tag_prefix', 'release-');

    expect(SemanticVersion::tag('2.0.0'))->toBe('release-2.0.0')
        ->and(SemanticVersion::fromTag('release-2.0.0'))->toBe('2.0.0');
});

it('picks the first well-formed semver tag from a git listing', function (): void {
    expect(SemanticVersion::firstTagIn("nightly\nv2.0.6-rc1\nv2.0.5\nv2.0.4\n"))->toBe('v2.0.5')
        ->and(SemanticVersion::firstTagIn("\n"))->toBeNull();
});
