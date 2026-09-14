# laravel-app-version

Git-derived application versions and commit-driven release notes for Laravel. The package is an API surface only: commands, models, and read-model methods that return plain data. Your app decides how to render it.

## What you get

- **`AppVersion`** reads `storage/app/version.json` (generated, never committed) and exposes `version()`, `build()`, `commit()`, `full()`, `stats()`, and `toArray()`. With no JSON present it falls back to the `VERSION` file with build `0` and commit `dev`.
- **`app:version`** writes that JSON from the `VERSION` file, semver git tags, commit counts, and `--numstat` totals (last commit, this build, lifetime).
- **`app:version:set 1.2.3`** bumps `VERSION` (and `APP_VERSION=` in env files when present), commits `chore: Bump version to 1.2.3`, tags `v1.2.3`, and regenerates the JSON.
- **`app:version:install-hooks`** writes a managed block into `post-commit`, `post-merge`, `post-checkout`, and `post-rewrite` so the build number stays current locally. The block uses `php` from PATH and falls back to the absolute path of the interpreter that ran the installer, so commits from IDEs, GUI clients, or agent shells without `php` on PATH still refresh the metadata. Re-running upgrades the block; `--uninstall` removes it.
- **`app:release-notes:publish`** compiles the commit subjects between the previous version boundary and HEAD into New / Improved / Fixed sections, capped summary sections, and feature groups, then upserts a `release_notes` row for the running version. `app:release-notes:backfill` previews or writes notes for older versions.
- **The compiler** drops noise (merges, dependabot, tests, chores, WIP, bumps, agent commits), honours conventional prefixes (`feat:` / `fix:` / `perf:` / `refactor!:`), strips PR refs, ticket keys, gitmoji, and backticks, classifies by a built-in verb lexicon plus fix-signal keywords ("crash", "stale", "false", "flap", …), renders in past voice ("Pinned X and linked Y") or as written (`voice: imperative`), de-duplicates, flags breaking changes, and groups items by feature area against both the raw subject and the rewritten sentence.
- **`ReleaseNote`** (version-keyed, ULID ids) with `published()`, `latestPublished()`, `currentPublished()`, `newerThan($version)`, and `toFeedArray()`.
- **`HasReleaseNoteReadState`** trait for your user model, backed by `release_note_reads`: `ensureReleaseNoteReadBootstrap()`, `markReleaseNotesPrompted()`, `markReleaseNotesRead()`, `clearReleaseNoteReadState()`.
- **`ReleaseNotesFeed`** turns those into one array per user: current release, banner flag, unread count, the capped list for an update summary, and `dismiss()` / `markAsRead()` mutations.

## Install

```bash
composer require medalink/laravel-app-version
php artisan vendor:publish --tag=app-version-config
echo "0.1.0" > VERSION
php artisan app:version:install-hooks
php artisan migrate
```

Add the trait to your user model:

```php
use Medalink\AppVersion\Concerns\HasReleaseNoteReadState;

class User extends Authenticatable
{
    use HasReleaseNoteReadState;
}
```

Run the hook installer from your composer scripts so every clone gets it:

```json
"post-install-cmd": ["@php artisan app:version:install-hooks --quiet"],
"post-update-cmd": ["@php artisan app:version:install-hooks --quiet"]
```

On deploy, after `git fetch --tags`:

```bash
php artisan app:version --no-interaction
php artisan app:release-notes:publish --no-interaction
```

### Deploying without Git or database access during the build

Create both artifacts while the build checkout still has complete Git history:

```bash
php artisan app:version --strict --no-interaction
php artisan app:release-notes:backfill --all --output=storage/app/release-notes.json --no-interaction
```

The export uses the same compiler and configuration as live publishing, including
custom release copy. It does not query or write the database. Ship both
`storage/app/version.json` and `storage/app/release-notes.json` in the build image.
After migrations, publish the snapshot into the configured release-note model:

```bash
php artisan app:release-notes:publish --from-file=storage/app/release-notes.json --no-interaction
```

Import does not access Git, rejects snapshots for another build, and upserts all
releases in one transaction. Repeated imports preserve original publication dates
and user read markers. Missing or malformed files fail the command. Use the
existing backfill `--from`, `--to`, or `--latest` options instead of `--all` to
export a narrower range. `--output` and `--force` cannot be combined.

`app:version --strict` fails on unreadable metadata or diff statistics instead of
silently writing zero counts. Partial clones must have their historical file
objects available before generation; commit metadata alone cannot provide line
counts. Build artifacts must be generated during build, not in an ephemeral
deployment-command filesystem.

## Version resolution

| Situation | Version | Build |
| --- | --- | --- |
| A `vX.Y.Z` tag points at HEAD | tag | `0` |
| Newest reachable tag is behind `VERSION` | `VERSION` | commits since `VERSION` last changed |
| Otherwise, a reachable tag exists | tag | commits since that tag |
| No tags | `VERSION` | commits since `VERSION` last changed |

The full string is `X.Y.Z.<build>+<short-sha>`.

## Rendering

Share `AppVersion::toArray()` and `app(ReleaseNotesFeed::class)->describe($user)` with your frontend and render them however fits your design. The feed shape:

```php
[
    'current' => ['version', 'headline', 'summary', 'publishedAt', 'sections', ...] | null,
    'title' => 'Updates since you last logged in',
    'unreadCount' => 2,
    'showBanner' => true,
    'releases' => [ /* capped list, each with summary sections */ ],
    'hiddenCount' => 0,
]
```

For an archive page use `ReleaseNote::published()` and `toFeedArray(compact: false)`.

## Owning the schema

Set `app-version.migrations` to `false` and write your own migrations (foreign keys, grants, row-level security). Point `app-version.models.*` at subclasses when you need extra casts or relations; the factory and every internal lookup resolve the configured classes.

## Tests

```bash
composer test
```
