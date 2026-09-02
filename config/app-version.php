<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Models\ReleaseNoteRead;

return [

    /*
    |--------------------------------------------------------------------------
    | Version sources
    |--------------------------------------------------------------------------
    |
    | `version_file` holds the semantic version (X.Y.Z) that `app:version:set`
    | bumps. `app:version` combines it with git metadata (semver tags, commit
    | counts, numstat totals) and writes the result to `json_path`, which is
    | what `AppVersion::data()` reads at runtime. When the JSON is missing the
    | reader falls back to the VERSION file with a build of 0 and commit "dev".
    |
    */

    'version_file' => env('APP_VERSION_FILE', base_path('VERSION')),

    'json_path' => env('APP_VERSION_JSON_PATH', storage_path('app/version.json')),

    'repository_path' => base_path(),

    'tag_prefix' => 'v',

    /*
    |--------------------------------------------------------------------------
    | app:version:set behaviour
    |--------------------------------------------------------------------------
    |
    | Env files (relative to the repository path) whose APP_VERSION line is
    | rewritten when present. `.env` is never staged for commit.
    |
    */

    'env_files' => ['.env', '.env.example'],

    'commit_on_set' => true,

    'tag_on_set' => true,

    /*
    |--------------------------------------------------------------------------
    | Git hooks (app:version:install-hooks)
    |--------------------------------------------------------------------------
    |
    | Each listed hook gets an idempotent block that regenerates version.json
    | so the build number and diff stats stay current on every commit, merge,
    | checkout, and rebase. Honors `core.hooksPath` when the repository sets it.
    |
    */

    'hooks' => ['post-commit', 'post-merge', 'post-checkout', 'post-rewrite'],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Set `migrations` to false when the host application ships its own
    | migrations for the two tables (for example to add grants, foreign keys,
    | or row-level security). Swap the models to host subclasses when the
    | application needs extra casts or relations on them.
    |
    */

    'migrations' => true,

    'models' => [
        'release_note' => ReleaseNote::class,
        'release_note_read' => ReleaseNoteRead::class,
    ],

    'cache' => [
        'store' => null,
        'ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Release notes generation
    |--------------------------------------------------------------------------
    */

    'release_notes' => [

        // Hand-written notes keyed by literal semantic version. Parsed commit
        // subjects are merged in underneath unless they duplicate an entry.
        'custom_releases' => [],

        // Commit subjects matching any pattern are dropped before parsing.
        'ignore_patterns' => [
            '/^\s*merge\b/i',
            '/\bdependabot\b/i',
            '/^(docs?|test|tests|chore|ci|build)(\(.+\))?:/i',
            '/\b(?:test|tests|testing|spec)\b/i',
            '/\b(?:cursor|codex|claude|copilot)\b/i',
            '/\bAGENTS\.md\b/i',
            '/\bCLAUDE\.md\b/i',
            '/\bsettings\.json\b/i',
            '/\b\.plan\.md\b/i',
            '/\bimplementation plans?\b/i',
            '/\bversion bump\b/i',
            '/\bbump version\b/i',
            '/\bschema dump\b/i',
            '/\bfor reference\b/i',
            '/^\s*ignore(?:s|d)?\b/i',
        ],

        // Ordered list of {title, summary, patterns[]}; the first match wins
        // and anything unmatched lands in "General Improvements".
        'feature_groups' => [],

        'section_prefixes' => [
            ReleaseNote::SECTION_NEW => [
                'add', 'added', 'introduce', 'introduced', 'create', 'created', 'new',
            ],
            ReleaseNote::SECTION_IMPROVED => [
                'improve', 'improved', 'enhance', 'enhanced', 'refine', 'refined',
                'standardize', 'standardized', 'update', 'updated', 'polish', 'polished',
                'make', 'made',
            ],
            ReleaseNote::SECTION_FIXED => [
                'fix', 'fixed', 'resolve', 'resolved', 'correct', 'corrected',
                'prevent', 'prevented', 'repair', 'repaired',
            ],
        ],

        // Regex => replacement applied to every subject before rewriting.
        'cleanup_replacements' => [
            '/\bUI\b/i' => 'interface',
            '/\bauth\b/i' => 'sign-in',
        ],

        // Regex => full replacement sentence for subjects that deserve a
        // hand-written explanation.
        'detail_rewrites' => [],

        'limits' => [
            'per_section' => 3,
            'max_releases_in_modal' => 3,
            'max_items_in_modal' => 9,
            'initial_range_commits' => 25,
            'manual_history_releases' => 1,
        ],

        // Null headline/summary resolve to "{app.name} has been updated" and
        // a generic sentence at runtime.
        'fallback' => [
            'headline' => null,
            'summary' => null,
            'sections' => [
                ReleaseNote::SECTION_NEW => [],
                ReleaseNote::SECTION_IMPROVED => [
                    'Improved reliability and polished the overall experience.',
                ],
                ReleaseNote::SECTION_FIXED => [],
            ],
        ],
    ],
];
