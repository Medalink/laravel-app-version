<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Support\SemanticVersion;
use RuntimeException;

/** Build-time release data, carried into a runtime that has no Git checkout. */
class ReleaseNotesSnapshot
{
    private const FORMAT_VERSION = 1;

    private const PAYLOAD_KEYS = [
        'version', 'previous_version', 'source_commit', 'source_range',
        'headline', 'summary', 'sections', 'summary_sections', 'feature_groups',
        'item_count', 'generation_mode', 'generation_warnings', 'published_at',
    ];

    /** @param list<array<string, mixed>> $releases */
    public static function write(string $path, array $releases): void
    {
        $json = json_encode([
            'format' => self::FORMAT_VERSION,
            'build' => AppVersion::full(),
            'releases' => $releases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        File::ensureDirectoryExists(dirname($path));
        File::replace($path, $json."\n");
    }

    public static function publish(string $path): int
    {
        if ($path === '' || ! File::isFile($path)) {
            throw new RuntimeException('Release snapshot file is missing. Export it during the build.');
        }

        $snapshot = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (is_array($snapshot) && isset($snapshot['release_notes'])) {
            $snapshot = $snapshot['release_notes'];
        }

        if (! is_array($snapshot) || ($snapshot['format'] ?? null) !== self::FORMAT_VERSION
            || ($snapshot['build'] ?? null) !== AppVersion::full()
            || ! is_array($snapshot['releases'] ?? null) || $snapshot['releases'] === []) {
            throw new RuntimeException('Release snapshot is invalid or belongs to a different build.');
        }

        $versions = [];

        foreach ($snapshot['releases'] as $release) {
            if (! is_array($release) || ! is_string($release['version'] ?? null)
                || ! SemanticVersion::isValid($release['version'])
                || isset($versions[$release['version']])
                || array_diff(self::PAYLOAD_KEYS, array_keys($release)) !== []
                || ! is_array($release['sections']) || ! is_int($release['item_count'])) {
                throw new RuntimeException('Release snapshot contains an invalid or duplicate release.');
            }

            $versions[$release['version']] = true;
        }

        $model = AppVersion::releaseNoteModel();

        $count = (new $model)->getConnection()->transaction(function () use ($model, $snapshot): int {
            foreach ($snapshot['releases'] as $payload) {
                $release = $model::query()->firstOrNew(['version' => $payload['version']]);
                $publishedAt = $release->published_at;
                $release->fill(Arr::only($payload, self::PAYLOAD_KEYS));

                if ($publishedAt !== null) {
                    $release->published_at = $publishedAt;
                }

                $release->save();
            }

            return count($snapshot['releases']);
        });

        $model::forgetPublishedCache();

        return $count;
    }
}
