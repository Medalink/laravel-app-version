<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Concerns\HasReleaseNoteReadState;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Models\ReleaseNoteRead;

/**
 * Read model for "what should this user see about recent releases": the
 * current release, whether to show a banner, the unread count, and the
 * capped list of releases for an update summary. Returns plain arrays so
 * the host renders them however it likes.
 *
 * The user model is expected to use {@see HasReleaseNoteReadState}.
 *
 * @phpstan-type Feed array{current: array<string, mixed>|null, title: string, unreadCount: int, showBanner: bool, releases: list<array<string, mixed>>, hiddenCount: int}
 */
class ReleaseNotesFeed
{
    /**
     * @return Feed
     */
    public function describe(?Model $user): array
    {
        $current = $this->currentRelease();

        if ($user === null || $current === null) {
            return $this->empty();
        }

        $state = $this->state($user, $current);
        $visible = $this->visibleReleasesFor($state);
        $unread = $this->unreadCountFor($state);

        return [
            'current' => $current->toFeedArray(),
            'title' => $this->titleFor($unread),
            'unreadCount' => $unread,
            'showBanner' => $this->shouldShowBannerFor($state, $current),
            'releases' => $visible->map(static fn (ReleaseNote $release): array => $release->toFeedArray())->values()->all(),
            'hiddenCount' => max($this->releaseSourceFor($state)->count() - $visible->count(), 0),
        ];
    }

    public function currentRelease(): ?ReleaseNote
    {
        return AppVersion::releaseNoteModel()::currentPublished();
    }

    public function unreadCount(Model $user): int
    {
        $current = $this->currentRelease();

        return $current === null ? 0 : $this->unreadCountFor($this->state($user, $current));
    }

    public function shouldShowBanner(Model $user): bool
    {
        $current = $this->currentRelease();

        return $current !== null && $this->shouldShowBannerFor($this->state($user, $current), $current);
    }

    /**
     * @return Collection<int, ReleaseNote>
     */
    public function visibleReleases(Model $user): Collection
    {
        $current = $this->currentRelease();

        return $current === null ? collect() : $this->visibleReleasesFor($this->state($user, $current));
    }

    /**
     * The banner was shown or dismissed for the current release; nothing is
     * marked read.
     */
    public function dismiss(Model $user): void
    {
        $current = $this->currentRelease();

        if ($current === null) {
            return;
        }

        $this->state($user, $current);
        $user->markReleaseNotesPrompted($current->version);
    }

    /**
     * Everything the user could have seen in the summary is now read.
     */
    public function markAsRead(Model $user): void
    {
        $current = $this->currentRelease();

        if ($current === null) {
            return;
        }

        $latestVisible = $this->visibleReleasesFor($this->state($user, $current))->first();

        if ($latestVisible === null) {
            return;
        }

        $user->markReleaseNotesRead($latestVisible->version);
    }

    /**
     * @return Feed
     */
    public function empty(): array
    {
        return [
            'current' => null,
            'title' => $this->titleFor(0),
            'unreadCount' => 0,
            'showBanner' => false,
            'releases' => [],
            'hiddenCount' => 0,
        ];
    }

    /**
     * @return array{last_seen_version?: string|null, last_prompted_version?: string|null, bootstrapped_at?: string|null}
     */
    protected function state(Model $user, ReleaseNote $current): array
    {
        return $user->ensureReleaseNoteReadBootstrap($current);
    }

    /**
     * @param  array{last_seen_version?: string|null, last_prompted_version?: string|null}  $state
     */
    protected function unreadCountFor(array $state): int
    {
        return AppVersion::releaseNoteModel()::newerThan($state[ReleaseNoteRead::LAST_SEEN_VERSION] ?? null)->count();
    }

    /**
     * @param  array{last_seen_version?: string|null, last_prompted_version?: string|null}  $state
     */
    protected function shouldShowBannerFor(array $state, ReleaseNote $current): bool
    {
        return AppVersion::releaseNoteModel()::newerThan($state[ReleaseNoteRead::LAST_PROMPTED_VERSION] ?? null)
            ->contains(static fn (ReleaseNote $release): bool => $release->version === $current->version);
    }

    /**
     * Unread releases newest first, or the most recent few when everything
     * has been read so a manual open still shows something.
     *
     * @param  array{last_seen_version?: string|null, last_prompted_version?: string|null}  $state
     * @return Collection<int, ReleaseNote>
     */
    protected function releaseSourceFor(array $state): Collection
    {
        $unread = AppVersion::releaseNoteModel()::newerThan($state[ReleaseNoteRead::LAST_SEEN_VERSION] ?? null);

        if ($unread->isNotEmpty()) {
            return $unread;
        }

        $limit = max((int) config('app-version.release_notes.limits.manual_history_releases', 1), 1);

        return AppVersion::releaseNoteModel()::published()->take($limit)->values();
    }

    /**
     * @param  array{last_seen_version?: string|null, last_prompted_version?: string|null}  $state
     * @return Collection<int, ReleaseNote>
     */
    protected function visibleReleasesFor(array $state): Collection
    {
        $source = $this->releaseSourceFor($state);
        $maxReleases = (int) config('app-version.release_notes.limits.max_releases_in_modal', 3);
        $maxItems = (int) config('app-version.release_notes.limits.max_items_in_modal', 9);
        $visible = collect();
        $itemCount = 0;

        foreach ($source as $release) {
            if ($visible->count() >= $maxReleases) {
                break;
            }

            $nextItemCount = $itemCount + $release->summaryItemCount();

            if ($visible->isNotEmpty() && $nextItemCount > $maxItems) {
                break;
            }

            $visible->push($release);
            $itemCount = $nextItemCount;
        }

        if ($visible->isEmpty() && $source->isNotEmpty()) {
            $visible->push($source->first());
        }

        return $visible;
    }

    protected function titleFor(int $unreadCount): string
    {
        if ($unreadCount > 0) {
            return 'Updates since you last logged in';
        }

        $name = config('app.name');

        return 'Latest '.(is_string($name) && $name !== '' ? $name : 'app').' update';
    }
}
