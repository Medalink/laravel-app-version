<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Medalink\AppVersion\Models\ReleaseNote;

/**
 * Turns raw commit subjects into user-facing release note sections.
 *
 * @phpstan-type Sections array{new: list<string>, improved: list<string>, fixed: list<string>}
 * @phpstan-type FeatureGroup array{title: string, summary: string, sections: Sections, item_count: int}
 * @phpstan-type Compiled array{headline: string, summary: string, sections: Sections, summary_sections: Sections, feature_groups: list<FeatureGroup>, item_count: int, generation_mode: string, generation_warnings: list<string>}
 */
class ReleaseNotesCompiler
{
    public const string GENERAL_GROUP_TITLE = 'General Improvements';

    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $warnings
     * @return Compiled
     */
    public function compile(array $subjects, array $warnings = []): array
    {
        $sections = ReleaseNote::emptySections();

        foreach ($subjects as $subject) {
            $normalized = $this->normalizeSubject($subject);

            if ($normalized === null) {
                continue;
            }

            $section = $this->classifySubject($normalized);
            $sections[$section][] = $this->rewriteSubject($normalized, $section);
        }

        $itemCount = count(Arr::flatten($sections));

        if ($itemCount === 0) {
            return $this->fallback($warnings);
        }

        return [
            'headline' => $this->buildHeadline($sections),
            'summary' => $this->buildSummary($sections),
            'sections' => $sections,
            'summary_sections' => $this->summarySections($sections),
            'feature_groups' => $this->featureGroups($sections),
            'item_count' => $itemCount,
            'generation_mode' => ReleaseNote::GENERATION_MODE_PARSED,
            'generation_warnings' => array_values($warnings),
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return Compiled
     */
    public function fallback(array $warnings = []): array
    {
        $sections = ReleaseNote::emptySections((array) $this->config('fallback.sections', []));

        return [
            'headline' => $this->fallbackHeadline(),
            'summary' => $this->fallbackSummary(),
            'sections' => $sections,
            'summary_sections' => $this->summarySections($sections),
            'feature_groups' => $this->featureGroups($sections),
            'item_count' => count(Arr::flatten($sections)),
            'generation_mode' => ReleaseNote::GENERATION_MODE_FALLBACK,
            'generation_warnings' => array_values($warnings),
        ];
    }

    public function fallbackHeadline(): string
    {
        $headline = $this->config('fallback.headline');

        return is_string($headline) && $headline !== ''
            ? $headline
            : $this->appName().' has been updated';
    }

    public function fallbackSummary(): string
    {
        $summary = $this->config('fallback.summary');

        return is_string($summary) && $summary !== ''
            ? $summary
            : 'This release includes stability improvements and general polish across the app.';
    }

    /**
     * @param  Sections  $sections
     * @return Sections
     */
    public function summarySections(array $sections): array
    {
        $limit = (int) $this->config('limits.per_section', 3);
        $capped = [];

        foreach (ReleaseNote::SECTIONS as $section) {
            $capped[$section] = array_slice($sections[$section] ?? [], 0, $limit);
        }

        return $capped;
    }

    /**
     * @param  Sections  $sections
     * @return list<FeatureGroup>
     */
    public function featureGroups(array $sections): array
    {
        $groups = [];

        foreach ((array) $this->config('feature_groups', []) as $group) {
            if (! is_array($group) || ! isset($group['title'])) {
                continue;
            }

            $groups[(string) $group['title']] = [
                'title' => (string) $group['title'],
                'summary' => (string) ($group['summary'] ?? ''),
                'patterns' => $group['patterns'] ?? [],
                'sections' => ReleaseNote::emptySections(),
            ];
        }

        $groups[self::GENERAL_GROUP_TITLE] ??= [
            'title' => self::GENERAL_GROUP_TITLE,
            'summary' => 'Additional changes, fixes, and polish.',
            'patterns' => [],
            'sections' => ReleaseNote::emptySections(),
        ];

        foreach (ReleaseNote::SECTIONS as $section) {
            foreach ($sections[$section] ?? [] as $item) {
                $title = $this->featureGroupTitleForItem($item, $groups) ?? self::GENERAL_GROUP_TITLE;
                $groups[$title]['sections'][$section][] = $item;
            }
        }

        return collect($groups)
            ->map(static function (array $group): array {
                unset($group['patterns']);
                $group['item_count'] = count(Arr::flatten($group['sections']));

                return $group;
            })
            ->filter(static fn (array $group): bool => $group['item_count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{title: string, summary: string, patterns: mixed, sections: Sections}>  $groups
     */
    protected function featureGroupTitleForItem(string $item, array $groups): ?string
    {
        foreach ($groups as $group) {
            foreach ((array) ($group['patterns'] ?? []) as $pattern) {
                if (is_string($pattern) && preg_match($pattern, $item)) {
                    return $group['title'];
                }
            }
        }

        return null;
    }

    protected function normalizeSubject(string $subject): ?string
    {
        $subject = trim($subject);

        if ($subject === '') {
            return null;
        }

        foreach ((array) $this->config('ignore_patterns', []) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $subject)) {
                return null;
            }
        }

        $subject = preg_replace('/^(feat|fix|chore|docs|refactor|test|perf|style)(\([^)]+\))?:\s*/i', '', $subject) ?? $subject;
        $subject = preg_replace('/^\[[^\]]+\]\s*/', '', $subject) ?? $subject;
        $subject = trim($subject, " \t\n\r\0\x0B-");

        return $subject !== '' ? $subject : null;
    }

    protected function classifySubject(string $subject): string
    {
        $subject = Str::lower($subject);

        foreach ((array) $this->config('section_prefixes', []) as $section => $prefixes) {
            if (! in_array($section, ReleaseNote::SECTIONS, true)) {
                continue;
            }

            foreach ((array) $prefixes as $prefix) {
                if ($subject === $prefix || Str::startsWith($subject, $prefix.' ')) {
                    return $section;
                }
            }
        }

        if (Str::contains($subject, [' fix ', ' fixed ', ' resolve', ' resolved', ' prevent', ' prevented'])) {
            return ReleaseNote::SECTION_FIXED;
        }

        return ReleaseNote::SECTION_IMPROVED;
    }

    protected function rewriteSubject(string $subject, string $section): string
    {
        foreach ((array) $this->config('cleanup_replacements', []) as $pattern => $replacement) {
            $subject = preg_replace($pattern, (string) $replacement, $subject) ?? $subject;
        }

        $subject = preg_replace('/\s+/', ' ', trim($subject)) ?? $subject;
        $subject = $this->expandDetailedRewrite($subject) ?? $this->rewriteSubjectWithFallback($subject, $section);
        $subject = Str::ucfirst(trim($subject));

        return rtrim($subject, '.').'.';
    }

    protected function expandDetailedRewrite(string $subject): ?string
    {
        $withoutPrefix = $this->stripLeadingActionPrefix($subject);

        foreach ((array) $this->config('detail_rewrites', []) as $pattern => $replacement) {
            if (preg_match($pattern, $subject) || preg_match($pattern, $withoutPrefix)) {
                return (string) $replacement;
            }
        }

        return null;
    }

    protected function stripLeadingActionPrefix(string $subject): string
    {
        return trim((string) preg_replace(
            '/^(add|added|introduce|introduced|create|created|new|improve|improved|enhance|enhanced|refine|refined|standardize|standardized|update|updated|polish|polished|make|made|fix|fixed|resolve|resolved|correct|corrected|prevent|prevented|repair|repaired)\b\s*/i',
            '',
            $subject,
            1,
        ));
    }

    protected function rewriteSubjectWithFallback(string $subject, string $section): string
    {
        $rewritten = match ($section) {
            ReleaseNote::SECTION_NEW => $this->rewriteNewSubject($subject),
            ReleaseNote::SECTION_FIXED => $this->rewriteFixedSubject($subject),
            default => $this->rewriteImprovedSubject($subject),
        };

        if ($rewritten !== null) {
            return $rewritten;
        }

        return match ($section) {
            ReleaseNote::SECTION_NEW => 'Added '.lcfirst($subject),
            ReleaseNote::SECTION_FIXED => 'Fixed '.lcfirst($subject),
            default => 'Improved '.lcfirst($subject),
        };
    }

    protected function rewriteNewSubject(string $subject): ?string
    {
        if (preg_match('/^(add|added|introduce|introduced|create|created|new)\s+(.+)$/i', trim($subject), $matches)) {
            return 'Added '.$matches[2];
        }

        return null;
    }

    protected function rewriteImprovedSubject(string $subject): ?string
    {
        $trimmed = trim($subject);

        if (preg_match('/^(make|made)\s+(.+?)\s+clickable$/i', $trimmed, $matches)) {
            return $matches[2].' are now clickable';
        }

        if (preg_match('/^(standardize|standardized)\s+(.+)$/i', $trimmed, $matches)) {
            return $matches[2].' are now more consistent';
        }

        if (preg_match('/^(improve|improved|enhance|enhanced|refine|refined|update|updated|polish|polished)\s+(.+)$/i', $trimmed, $matches)) {
            return $matches[2].' have been improved';
        }

        return null;
    }

    protected function rewriteFixedSubject(string $subject): ?string
    {
        $trimmed = trim($subject);

        if (preg_match('/^(fix|fixed|resolve|resolved|correct|corrected|repair|repaired)\s+(.+)$/i', $trimmed, $matches)) {
            return $matches[2].' now works correctly';
        }

        if (preg_match('/^(prevent|prevented)\s+(.+)$/i', $trimmed, $matches)) {
            return $matches[2].' is now prevented';
        }

        return null;
    }

    /**
     * @param  Sections  $sections
     */
    protected function buildHeadline(array $sections): string
    {
        $app = $this->appName();

        return match (true) {
            $sections[ReleaseNote::SECTION_NEW] !== [] && $sections[ReleaseNote::SECTION_FIXED] !== [] => 'New features and fixes are live',
            $sections[ReleaseNote::SECTION_NEW] !== [] => 'New features are now available',
            $sections[ReleaseNote::SECTION_FIXED] !== [] => "{$app} now includes fresh fixes",
            default => "{$app} has been improved",
        };
    }

    /**
     * @param  Sections  $sections
     */
    protected function buildSummary(array $sections): string
    {
        $labels = [
            ReleaseNote::SECTION_NEW => 'new feature',
            ReleaseNote::SECTION_IMPROVED => 'improvement',
            ReleaseNote::SECTION_FIXED => 'fix',
        ];
        $parts = [];

        foreach ($labels as $section => $label) {
            $count = count($sections[$section]);

            if ($count === 0) {
                continue;
            }

            $parts[] = sprintf('%s %s%s', number_format($count), $label, $count === 1 ? '' : 's');
        }

        if ($parts === []) {
            return $this->fallbackSummary();
        }

        return 'This release includes '.implode(', ', $parts).'.';
    }

    protected function appName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'The app';
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config('app-version.release_notes.'.$key, $default);
    }
}
