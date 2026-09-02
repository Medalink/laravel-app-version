<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Contracts\ReleaseCommitSource;
use Medalink\AppVersion\Models\ReleaseNote;
use Throwable;

/**
 * Builds and stores the release note for a version from the commits between
 * it and the previous version boundary.
 *
 * @phpstan-import-type Sections from ReleaseNotesCompiler
 * @phpstan-import-type Compiled from ReleaseNotesCompiler
 *
 * @phpstan-type Custom array{headline: string, summary: string, sections: Sections, summary_sections: Sections, feature_groups: list<array<string, mixed>>, item_count: int, generation_mode: string}
 */
class ReleaseNotesPublisher
{
    public function __construct(
        protected ReleaseCommitSource $commitSource,
        protected ReleaseNotesCompiler $compiler,
    ) {}

    /**
     * @return list<array{version: string, commit: string}>
     */
    public function versionHistory(): array
    {
        return $this->commitSource->versionHistory();
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForVersion(string $version, bool $strict = false, ?string $fromVersionOverride = null): array
    {
        $history = $this->safeVersionHistory($strict);
        $currentRef = $this->findVersionRef($history, $version);
        $previousRef = $fromVersionOverride !== null
            ? $this->findVersionRef($history, $fromVersionOverride)
            : $this->findPreviousVersionRef($history, $version);
        $previousVersion = $fromVersionOverride ?: ($previousRef['version'] ?? null);
        $warnings = [];

        if ($fromVersionOverride !== null && $previousRef === null) {
            $warnings[] = "Requested base version {$fromVersionOverride} was not found in git VERSION history.";
        }

        if ($currentRef === null) {
            $warnings[] = "Version {$version} was not found in git VERSION history.";
        }

        $toRef = $version === AppVersion::version() ? 'HEAD' : ($currentRef['commit'] ?? 'HEAD');
        $sourceCommit = $toRef === 'HEAD'
            ? ($this->commitSource->currentCommit() ?? ($currentRef['commit'] ?? null))
            : ($currentRef['commit'] ?? null);

        $fromRef = $previousVersion !== null ? $this->resolveFromRef($previousRef, $previousVersion) : null;

        if ($previousVersion !== null && $fromRef === null) {
            $warnings[] = "No commit boundary found for {$previousVersion}; using a limited recent history fallback.";
        }

        $custom = $this->customReleasePayload($version);
        $subjects = $this->collectSubjects($fromRef, $toRef, $version, $strict, $warnings);
        $compiled = $this->compiler->compile($subjects, $warnings);
        $payload = $custom !== null ? $this->mergeCustomReleasePayload($custom, $compiled) : $compiled;

        return array_merge($payload, [
            'version' => $version,
            'previous_version' => $previousVersion,
            'source_commit' => $sourceCommit,
            'source_range' => $fromRef !== null ? "{$fromRef}..{$toRef}" : $toRef,
            'published_at' => Carbon::now(),
        ]);
    }

    public function publish(string $version, bool $strict = false, ?string $fromVersionOverride = null): ReleaseNote
    {
        $payload = $this->payloadForVersion($version, $strict, $fromVersionOverride);
        $model = AppVersion::releaseNoteModel();

        return $model::query()->updateOrCreate(
            ['version' => $version],
            Arr::except($payload, ['version']),
        );
    }

    /**
     * @param  array{version: string, commit: string}|null  $previousRef
     */
    protected function resolveFromRef(?array $previousRef, string $previousVersion): ?string
    {
        $fromRef = $previousRef['commit'] ?? null;

        if (is_string($fromRef) && $fromRef !== '') {
            return $fromRef;
        }

        $fromRef = $this->commitSource->tagCommit($previousVersion);

        if (is_string($fromRef) && $fromRef !== '') {
            return $fromRef;
        }

        $stored = AppVersion::releaseNoteModel()::forVersion($previousVersion)?->source_commit;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    protected function collectSubjects(?string $fromRef, string $toRef, string $version, bool $strict, array &$warnings): array
    {
        try {
            return $this->commitSource->commitSubjects($fromRef, $toRef);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }

            $warnings[] = 'Commit lookup failed: '.$e->getMessage();
            Log::warning('Release note commit lookup failed', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return Custom|null
     */
    protected function customReleasePayload(string $version): ?array
    {
        $release = config('app-version.release_notes.custom_releases', [])[$version] ?? null;

        if (! is_array($release)) {
            return null;
        }

        $sections = ReleaseNote::emptySections((array) ($release['sections'] ?? []));
        $summarySections = array_key_exists('summary_sections', $release)
            ? ReleaseNote::emptySections((array) ($release['summary_sections'] ?? []))
            : $this->compiler->summarySections($sections);

        return [
            'headline' => (string) ($release['headline'] ?? $this->compiler->fallbackHeadline()),
            'summary' => (string) ($release['summary'] ?? $this->compiler->fallbackSummary()),
            'sections' => $sections,
            'summary_sections' => $summarySections,
            'feature_groups' => $this->compiler->featureGroups($sections),
            'item_count' => count(Arr::flatten($sections)),
            'generation_mode' => ReleaseNote::GENERATION_MODE_CUSTOM,
        ];
    }

    /**
     * @param  Custom  $custom
     * @param  Compiled  $compiled
     * @return array<string, mixed>
     */
    protected function mergeCustomReleasePayload(array $custom, array $compiled): array
    {
        $sections = $custom['sections'];
        $added = 0;

        if ($compiled['generation_mode'] === ReleaseNote::GENERATION_MODE_PARSED) {
            $seen = [];

            foreach ($sections as $items) {
                foreach ($items as $item) {
                    $seen[$this->releaseItemKey($item)] = true;
                }
            }

            foreach (ReleaseNote::SECTIONS as $section) {
                foreach ($compiled['sections'][$section] as $item) {
                    $key = $this->releaseItemKey($item);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $sections[$section][] = $item;
                    $seen[$key] = true;
                    $added++;
                }
            }
        }

        return [
            ...$custom,
            'sections' => $sections,
            'summary_sections' => $this->customSummarySections($custom, $sections),
            'feature_groups' => $this->compiler->featureGroups($sections),
            'item_count' => count(Arr::flatten($sections)),
            'generation_mode' => $added > 0 ? ReleaseNote::GENERATION_MODE_CUSTOM_PARSED : ReleaseNote::GENERATION_MODE_CUSTOM,
            'generation_warnings' => $compiled['generation_warnings'],
        ];
    }

    /**
     * Hand-curated summary sections win; derived ones follow the merged list.
     *
     * @param  Custom  $custom
     * @param  Sections  $sections
     * @return Sections
     */
    protected function customSummarySections(array $custom, array $sections): array
    {
        if ($custom['summary_sections'] !== $this->compiler->summarySections($custom['sections'])) {
            return $custom['summary_sections'];
        }

        return $this->compiler->summarySections($sections);
    }

    protected function releaseItemKey(string $item): string
    {
        return strtolower(trim($item, " \t\n\r\0\x0B."));
    }

    /**
     * @return list<array{version: string, commit: string}>
     */
    protected function safeVersionHistory(bool $strict): array
    {
        try {
            return $this->commitSource->versionHistory();
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }

            Log::warning('Release note version history lookup failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<array{version: string, commit: string}>  $history
     * @return array{version: string, commit: string}|null
     */
    protected function findVersionRef(array $history, string $version): ?array
    {
        foreach ($history as $entry) {
            if ($entry['version'] === $version) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  list<array{version: string, commit: string}>  $history
     * @return array{version: string, commit: string}|null
     */
    protected function findPreviousVersionRef(array $history, string $version): ?array
    {
        foreach ($history as $index => $entry) {
            if ($entry['version'] === $version && isset($history[$index + 1])) {
                return $history[$index + 1];
            }
        }

        $stored = AppVersion::releaseNoteModel()::published()
            ->first(static fn (ReleaseNote $release): bool => $release->version !== $version);

        if ($stored === null) {
            return null;
        }

        return [
            'version' => $stored->version,
            'commit' => (string) ($stored->source_commit ?? ''),
        ];
    }
}
