<?php

namespace Medalink\AppVersion\Support;

final class SemanticVersion
{
    public const string PATTERN = '/^\d+\.\d+\.\d+$/';

    public static function isValid(?string $version): bool
    {
        return is_string($version) && preg_match(self::PATTERN, $version) === 1;
    }

    public static function compare(string $left, string $right): int
    {
        return version_compare($left, $right);
    }

    /**
     * Whether $candidate is strictly newer than $than. A null $than means
     * "nothing has been seen yet", so any candidate counts as newer.
     */
    public static function isNewer(string $candidate, ?string $than): bool
    {
        if ($than === null || $than === '') {
            return true;
        }

        return self::compare($candidate, $than) > 0;
    }

    public static function tagPrefix(): string
    {
        return (string) config('app-version.tag_prefix', 'v');
    }

    public static function tag(string $version): string
    {
        return self::tagPrefix().$version;
    }

    public static function fromTag(string $tag): string
    {
        $prefix = self::tagPrefix();

        if ($prefix !== '' && str_starts_with($tag, $prefix)) {
            return substr($tag, strlen($prefix));
        }

        return $tag;
    }

    /**
     * Glob passed to `git tag --list` / `git describe --match`.
     */
    public static function tagGlob(): string
    {
        return self::tagPrefix().'[0-9]*.[0-9]*.[0-9]*';
    }

    /**
     * First well-formed semver tag in a newline-separated git listing.
     */
    public static function firstTagIn(string $output): ?string
    {
        foreach (preg_split('/\R+/', trim($output)) ?: [] as $tag) {
            $tag = trim($tag);

            if (str_starts_with($tag, self::tagPrefix()) && self::isValid(self::fromTag($tag))) {
                return $tag;
            }
        }

        return null;
    }
}
