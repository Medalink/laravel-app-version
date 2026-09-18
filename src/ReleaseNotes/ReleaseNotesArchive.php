<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Support\Collection;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Models\ReleaseNote;

/**
 * Read model for a version-history page. The newest few releases carry
 * their full sections and feature groups; everything older ships as a
 * digest (headline, version, date, item count) whose body a host fetches
 * on demand through {@see find()}, so the page stays flat as history grows.
 *
 * {@see describe()} returns plain arrays for a client-rendered host;
 * {@see expanded()} and {@see older()} return the models for a
 * server-rendered one.
 *
 * @phpstan-type Archive array{releases: list<array<string, mixed>>, olderReleases: list<array<string, mixed>>, total: int, expandedLimit: int}
 */
class ReleaseNotesArchive
{
    public const int DEFAULT_EXPANDED_RELEASES = 2;

    /**
     * How many of the newest releases render in full.
     */
    public function expandedLimit(): int
    {
        return max((int) config('app-version.release_notes.limits.archive_expanded_releases', self::DEFAULT_EXPANDED_RELEASES), 0);
    }

    /**
     * Every published release, newest version first.
     *
     * @return Collection<int, ReleaseNote>
     */
    public function all(): Collection
    {
        return AppVersion::releaseNoteModel()::published();
    }

    /**
     * The newest releases, rendered with every item and feature group.
     *
     * @return Collection<int, ReleaseNote>
     */
    public function expanded(): Collection
    {
        return $this->all()->take($this->expandedLimit())->values();
    }

    /**
     * Everything older, collapsed to a digest until opened.
     *
     * @return Collection<int, ReleaseNote>
     */
    public function older(): Collection
    {
        return $this->all()->slice($this->expandedLimit())->values();
    }

    /**
     * The full body of one collapsed release once it is opened.
     */
    public function find(string $version): ?ReleaseNote
    {
        return AppVersion::releaseNoteModel()::forVersion($version);
    }

    /**
     * @return Archive
     */
    public function describe(): array
    {
        $all = $this->all();
        $limit = $this->expandedLimit();

        return [
            'releases' => $all->take($limit)
                ->map(static fn (ReleaseNote $release): array => $release->toFeedArray(compact: false))
                ->values()
                ->all(),
            'olderReleases' => $all->slice($limit)
                ->map(static fn (ReleaseNote $release): array => $release->toDigestArray())
                ->values()
                ->all(),
            'total' => $all->count(),
            'expandedLimit' => $limit,
        ];
    }
}
