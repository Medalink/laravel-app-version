<?php

namespace Medalink\AppVersion\Models;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Database\Factories\ReleaseNoteFactory;
use Medalink\AppVersion\Support\SemanticVersion;

/**
 * One published release. Rows are keyed by semantic version, so every
 * "newer than" question is answered with version_compare rather than the
 * primary key; the id only needs to be unique.
 *
 * @property string $id
 * @property string $version
 * @property string|null $previous_version
 * @property string|null $source_commit
 * @property string|null $source_range
 * @property string $headline
 * @property string $summary
 * @property array<string, list<string>> $sections
 * @property array<string, list<string>>|null $summary_sections
 * @property list<array<string, mixed>>|null $feature_groups
 * @property int $item_count
 * @property Carbon|null $published_at
 * @property string $generation_mode
 * @property list<string>|null $generation_warnings
 */
class ReleaseNote extends Model
{
    /** @use HasFactory<ReleaseNoteFactory> */
    use HasFactory, HasUlids;

    public const string SECTION_NEW = 'new';

    public const string SECTION_IMPROVED = 'improved';

    public const string SECTION_FIXED = 'fixed';

    /** @var list<string> */
    public const array SECTIONS = [self::SECTION_NEW, self::SECTION_IMPROVED, self::SECTION_FIXED];

    public const string GENERATION_MODE_PARSED = 'parsed';

    public const string GENERATION_MODE_FALLBACK = 'fallback';

    public const string GENERATION_MODE_CUSTOM = 'custom';

    public const string GENERATION_MODE_CUSTOM_PARSED = 'custom+parsed';

    private const string PUBLISHED_CACHE_KEY = 'app-version:release-notes:published';

    protected $table = 'release_notes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'summary_sections' => 'array',
            'feature_groups' => 'array',
            'generation_warnings' => 'array',
            'item_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => static::forgetPublishedCache());
        static::deleted(static fn () => static::forgetPublishedCache());
    }

    protected static function newFactory(): ReleaseNoteFactory
    {
        return ReleaseNoteFactory::new();
    }

    /**
     * Every published release, newest version first. The raw attribute rows
     * are cached (never model objects, so a cache store can rehydrate them
     * without autoloading this class) and invalidated on any save or delete.
     *
     * @return Collection<int, static>
     */
    public static function published(): Collection
    {
        $rows = static::cache()->remember(
            self::PUBLISHED_CACHE_KEY,
            (int) config('app-version.cache.ttl', 3600),
            static fn (): array => static::query()
                ->get()
                ->map(static fn (self $release): array => $release->getAttributes())
                ->all(),
        );

        return static::query()
            ->hydrate(is_array($rows) ? $rows : [])
            ->sort(static fn (self $left, self $right): int => SemanticVersion::compare($right->version, $left->version))
            ->values();
    }

    public static function latestPublished(): ?static
    {
        return static::published()->first();
    }

    public static function forVersion(string $version): ?static
    {
        return static::published()->first(static fn (self $release): bool => $release->version === $version);
    }

    /**
     * The release matching the running application version, or the newest
     * published one when the running version has no notes yet.
     */
    public static function currentPublished(): ?static
    {
        return static::forVersion(AppVersion::version()) ?? static::latestPublished();
    }

    /**
     * Releases strictly newer than $version, newest first. Null means
     * "nothing has been seen", so everything is returned.
     *
     * @return Collection<int, static>
     */
    public static function newerThan(?string $version): Collection
    {
        return static::published()
            ->filter(static fn (self $release): bool => SemanticVersion::isNewer($release->version, $version))
            ->values();
    }

    public static function forgetPublishedCache(): void
    {
        static::cache()->forget(self::PUBLISHED_CACHE_KEY);
    }

    /**
     * @return array{new: list<string>, improved: list<string>, fixed: list<string>}
     */
    public function normalizedSections(): array
    {
        return self::emptySections($this->sections ?? []);
    }

    /**
     * @return array{new: list<string>, improved: list<string>, fixed: list<string>}
     */
    public function summarySections(): array
    {
        return self::emptySections($this->summary_sections ?? $this->normalizedSections());
    }

    /**
     * @return list<array{title: string, summary: string, sections: array{new: list<string>, improved: list<string>, fixed: list<string>}, item_count?: int}>
     */
    public function featureGroups(): array
    {
        if (is_array($this->feature_groups) && $this->feature_groups !== []) {
            return $this->feature_groups;
        }

        return [[
            'title' => 'Release Updates',
            'summary' => $this->summary,
            'sections' => $this->normalizedSections(),
            'item_count' => $this->item_count,
        ]];
    }

    public function summaryItemCount(): int
    {
        return array_sum(array_map('count', $this->summarySections()));
    }

    /**
     * A client-safe payload. Compact payloads carry the capped summary
     * sections for banners and modals; full payloads carry every item plus
     * feature groups for an archive page.
     *
     * @return array{version: string, previousVersion: string|null, headline: string, summary: string, publishedAt: string|null, sections: array{new: list<string>, improved: list<string>, fixed: list<string>}, featureGroups: list<array<string, mixed>>, itemCount: int, generationMode: string}
     */
    public function toFeedArray(bool $compact = true): array
    {
        return [
            'version' => $this->version,
            'previousVersion' => $this->previous_version,
            'headline' => $this->headline,
            'summary' => $this->summary,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'sections' => $compact ? $this->summarySections() : $this->normalizedSections(),
            'featureGroups' => $compact ? [] : $this->featureGroups(),
            'itemCount' => (int) $this->item_count,
            'generationMode' => $this->generation_mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array{new: list<string>, improved: list<string>, fixed: list<string>}
     */
    public static function emptySections(array $sections = []): array
    {
        return array_replace([
            self::SECTION_NEW => [],
            self::SECTION_IMPROVED => [],
            self::SECTION_FIXED => [],
        ], array_intersect_key($sections, array_flip(self::SECTIONS)));
    }

    protected static function cache(): CacheRepository
    {
        $store = config('app-version.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
