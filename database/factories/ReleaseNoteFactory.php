<?php

namespace Medalink\AppVersion\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Models\ReleaseNote;

/**
 * @extends Factory<ReleaseNote>
 */
class ReleaseNoteFactory extends Factory
{
    /**
     * Resolve the configured model so host subclasses get factory support
     * without redeclaring one.
     *
     * @return class-string<ReleaseNote>
     */
    public function modelName(): string
    {
        return AppVersion::releaseNoteModel();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sections = [
            ReleaseNote::SECTION_NEW => ['Added a clearer update summary for recent changes.'],
            ReleaseNote::SECTION_IMPROVED => ['Improved reliability and polished the overall experience.'],
            ReleaseNote::SECTION_FIXED => ['Fixed smaller issues across the interface.'],
        ];

        return [
            'version' => sprintf('%d.%d.%d', fake()->numberBetween(1, 4), fake()->numberBetween(0, 9), fake()->numberBetween(0, 9)),
            'previous_version' => null,
            'source_commit' => fake()->sha1(),
            'source_range' => fake()->sha1().'..'.fake()->sha1(),
            'headline' => 'The app has been updated',
            'summary' => 'This release includes a few visible improvements and fixes.',
            'sections' => $sections,
            'summary_sections' => $sections,
            'feature_groups' => null,
            'item_count' => count(array_merge(...array_values($sections))),
            'published_at' => now(),
            'generation_mode' => ReleaseNote::GENERATION_MODE_PARSED,
            'generation_warnings' => [],
        ];
    }

    public function fallback(): static
    {
        $sections = [
            ReleaseNote::SECTION_NEW => [],
            ReleaseNote::SECTION_IMPROVED => ['Improved reliability and polished the overall experience.'],
            ReleaseNote::SECTION_FIXED => [],
        ];

        return $this->state(fn (): array => [
            'generation_mode' => ReleaseNote::GENERATION_MODE_FALLBACK,
            'sections' => $sections,
            'summary_sections' => $sections,
            'item_count' => 1,
        ]);
    }
}
