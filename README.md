# laravel-app-version

Git-derived application versions and commit-driven release notes for Laravel. The package is an API surface only: commands, models, and read-model methods that return plain data. Your app decides how to render it.

## What you get

- **`AppVersion`** reads `storage/app/version.json` (generated, never committed) and exposes `version()`, `build()`, `commit()`, `full()`, `stats()`, and `toArray()`. With no JSON present it falls back to the `VERSION` file with build `0` and commit `dev`.
- **`app:version`** writes that JSON from the `VERSION` file, semver git tags, commit counts, and `--numstat` totals (last commit, this build, lifetime). `--stats-cache=<file>` makes repeat runs read only new history; `--output=<path>` writes somewhere other than the configured path.
- **`app:version:set 1.2.3`** bumps `VERSION` (and `APP_VERSION=` in env files when present), commits `chore: Bump version to 1.2.3`, tags `v1.2.3`, and regenerates the JSON.
- **`app:version:install-hooks`** writes a managed block into `post-commit`, `post-merge`, `post-checkout`, and `post-rewrite` so the build number stays current locally. The block uses `php` from PATH and falls back to the absolute path of the interpreter that ran the installer, so commits from IDEs, GUI clients, or agent shells without `php` on PATH still refresh the metadata. Re-running upgrades the block; `--uninstall` removes it.
- **`app:release-notes:publish`** compiles the commit subjects between the previous version boundary and HEAD into New / Improved / Fixed sections, capped summary sections, and feature groups, then upserts a `release_notes` row for the running version. `app:release-notes:backfill` previews or writes notes for older versions.
- **The compiler** drops noise (merges, dependabot, tests, chores, WIP, bumps, agent commits), honours conventional prefixes (`feat:` / `fix:` / `perf:` / `refactor!:`), strips PR refs, ticket keys, gitmoji, and backticks, classifies by a built-in verb lexicon plus fix-signal keywords ("crash", "stale", "false", "flap", …), renders in past voice ("Pinned X and linked Y") or as written (`voice: imperative`), de-duplicates, flags breaking changes, and groups items by feature area against both the raw subject and the rewritten sentence. With `release_notes.unit = pull_request` it works on merged pull requests instead and rolls them up into a few ranked areas (see [Pull requests instead of commits](#pull-requests-instead-of-commits)).
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

### One committed file for Cloud and other Git-free deployments

Enable flat mode in your published `config/app-version.php`:

```php
'flat' => true,
'flat_path' => base_path('version-info.json'),
```

Generate locally from a complete Git checkout:

```bash
php artisan app:version --flat
php artisan app:version:install-hooks --flat
git add version-info.json
git commit -m "chore: record version snapshot"
git push
```

The single file contains version metadata, line counts, version history, and
compiled release notes. It needs no database to generate. The hooks refresh it
after source commits; commit the resulting file before pushing a release. The
snapshot's `source_commit` identifies the source commit before the artifact-only
commit. A commit cannot contain its own hash. The hook recognizes that final
artifact-only commit and leaves the file unchanged.

`AppVersion` reads this committed file at runtime when `flat` is enabled. Remove
Git-based generation from Cloud build commands. After migrations, load the same
file into the existing release-note feed and read-state system:

```bash
php artisan app:release-notes:publish --from-file=version-info.json --no-interaction
```

No Git executable, repository history, or Git credentials are needed on Cloud.
Flat generation fails on missing Git data and preserves the previous complete
file. It does not silently substitute zero counts. Import validates the snapshot
and upserts releases transactionally, preserving publication dates and read state.

### Separate build artifacts

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

### Incremental generation

Lifetime and build line counts come from `git log --numstat` over the whole
history, which grows with every commit. Give the command a cache file and it
only reads what it has not seen:

```bash
php artisan app:version --flat --stats-cache=/var/cache/app/version-stats.json \
    --output=/srv/releases/42/version-info.json --no-interaction
```

The cache keeps, per processed commit, the additions, deletions and commit
count over everything reachable from it, the earliest root-commit date, and
the VERSION file's commit listing. A later run lists only the commits since
the cached ancestor with the most history (`git log --numstat HEAD ^<ancestor>`,
which also proves the ancestry), adds them up, and takes a build range as the
difference between HEAD and its cached boundary. The VERSION listing is reused
when the file is unchanged along the first-parent chain back to that ancestor
(one `git diff-tree`); tags come from one `git for-each-ref`. The nearest
reachable tag still comes from `git describe`, and the running version's
subjects from `git log`. A steady run starts about ten Git processes, none of
which walks the whole history except `git describe` (fast with a
commit-graph).

Cached entries belong to a context: the Git version; the diff, log, attribute
and i18n configuration and `core.bigFileThreshold`; the contents of the
attributes files Git reads (the repository's `info/attributes` through the
common directory; the global file; the system file where Git 2.42 or later
names it; the work tree's top-level `.gitattributes` and every tracked one, in
the index and the work tree); the shallow and graft files; replace refs; and
the environment variables that redirect them. Git looks a listed path's
attributes up in the work tree's `.gitattributes` of every directory above
it, tracked, untracked or ignored, so the cache also keeps each directory its
histories list and the contents of the `.gitattributes` there; one that
appears, changes or goes away discards the entries. The context names no path,
so a deploy that checks every release out in a new linked worktree builds on
the previous release's run. Another context, a rewritten history or no cached
ancestor reads the whole history once. Attributes read from a tree
(`attr.tree`, `GIT_ATTR_SOURCE`) move with that tree, so with either set the
command collects everything without the cache. With `--flat` the cache also keeps each earlier release's notes,
keyed by the commits that bound it, the release-notes configuration, that
context and the code that compiles them (by class name and contents, so a
release's own copy of `vendor/` reuses them); the running version is always
compiled. The output is byte-for-byte what the command writes without the
cache.

The file is only a cache: delete it at any time; an unreadable one starts
empty. An entry naming a commit the repository no longer has (a pruned branch
deploy, a new clone under a kept cache, `gc` after a force-push) is dropped by
the run that meets it, which builds on the other entries. Any other failure of
the cached read is a cache miss in every mode: the command collects everything
without the cache, and `--strict` or `--flat` holds that collection to its
rules.

`scripts/verify-stats-cache.php` replays a real repository's recent history
(from a checkout of this package) and fails unless every cached run matches
the uncached one. It also reports whether each cached run read only the new
commits. `--worktree-per-commit` checks every commit out in a new linked
worktree, as a deploy with one worktree per release does:

```bash
php scripts/verify-stats-cache.php /path/to/app --commits=20 --ref=origin/main \
    --config=/path/to/app/config/app-version.php --app-name=App --worktree-per-commit
```

`--output` writes the metadata to that file instead of `json_path` (or
`flat_path` with `--flat`). An explicit `--output` is always written, even when
HEAD only records the committed snapshot.

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

For a version-history page use `app(ReleaseNotesArchive::class)`. The newest `release_notes.limits.archive_expanded_releases` releases (default 2) come back in full; everything older is a digest without sections or feature groups, so the page stays flat as history grows. `describe()` returns arrays for a client-rendered page, `expanded()` / `older()` return the models for a server-rendered one, and `find($version)` fetches one collapsed body once the row is opened:

```php
[
    'releases' => [ /* newest, each with full sections and featureGroups */ ],
    'olderReleases' => [ /* ReleaseNote::toDigestArray(): version, previousVersion, headline, summary, publishedAt, itemCount, generationMode */ ],
    'total' => 7,
    'expandedLimit' => 2,
]
```

### Pull requests instead of commits

When work lands as dozens of commits per change, commit-level notes inflate ("250 new features"). Set `release_notes.unit` to `pull_request` and every merged pull request becomes one item instead, titled by its merge body, with its commits folded in:

- One `git log --topo-order` of the range is split in memory (`PullRequestHistory`). Pull requests merged into a release branch or a stack are listed on their own; the carrier keeps only the commits made on it directly. Commits made straight on the mainline stay single items.
- `branch_types` types each pull request by branch prefix (`feat/` new, `fix/` and `security/` fixed, `perf/` improved) before the title's own prefix and verb; `ignore_branches` drops `docs/`, `test/`, `chore/`, `dependabot/` and the like.
- `rollup_keys` (regexes with one capture group, tried on the title and branch) join related pull requests into one change, read as its biggest new feature with the others as details. `'/\bplan (\d+)\b/i'` joins every phase and follow-up of a plan.
- Feature groups match a change's own title and branch first, then the members it rolled up. Groups come back busiest first, each with its `changes` ranked by size (new features counting double), and the summary reads "This release brings 7 major updates, plus 284 smaller improvements and fixes."
- `ReleaseNote::highlights()` (also `highlights` on `toFeedArray()`) is the update-modal view: the first `limits.modal_groups` areas (default 7), each with its best `limits.highlights_per_group` changes (default 2) and a `more` count. It is empty for releases compiled from commits, so hosts can render either.

Without a previous release to diff against, or when the pull request lookup fails, publishing falls back to commit subjects and records a warning.

`ReleaseNote::SECTION_LABELS` maps the section keys to their display labels, and `AppVersion::commitSpanLabel()` renders "563 commits in 14 days" from the recorded first and running commit dates (`commitSpanDays()` for the number, also on `toArray()`). `AppVersion::buildSpanDays()` does the same for this build: whole days from the tag or `VERSION` change the build counts from (`buildStartedAt()`, written as `build_started_at`) to the running commit, null at an exact tag.

## Owning the schema

Set `app-version.migrations` to `false` and write your own migrations (foreign keys, grants, row-level security). Point `app-version.models.*` at subclasses when you need extra casts or relations; the factory and every internal lookup resolve the configured classes.

## Tests

```bash
composer test
```
