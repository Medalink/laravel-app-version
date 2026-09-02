<?php

namespace Medalink\AppVersion\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Models\ReleaseNoteRead;

/**
 * Per-user release note read state for the authenticatable model. State is
 * version-keyed: "seen" means the user marked the release as read, "prompted"
 * means the banner for it has been shown or dismissed.
 *
 * @mixin Model
 */
trait HasReleaseNoteReadState
{
    /** @return HasOne<ReleaseNoteRead, $this> */
    public function releaseNoteRead(): HasOne
    {
        return $this->hasOne(AppVersion::releaseNoteReadModel(), 'user_id');
    }

    /**
     * Empty until the state is bootstrapped or written for the first time.
     *
     * @return array{}|array{last_seen_version: string|null, last_prompted_version: string|null, bootstrapped_at: string|null}
     */
    public function releaseNoteReadState(): array
    {
        $read = $this->releaseNoteRead()->first();

        return $read instanceof ReleaseNoteRead ? $read->toStateArray() : [];
    }

    /**
     * Existing users start out having "seen" everything up to the release
     * before the current one, so the current release is the only thing that
     * shows as new. New installs with no prior release see everything.
     *
     * @return array{}|array{last_seen_version: string|null, last_prompted_version: string|null, bootstrapped_at: string|null}
     */
    public function ensureReleaseNoteReadBootstrap(?ReleaseNote $latestRelease): array
    {
        $state = $this->releaseNoteReadState();

        if ($state !== [] || $latestRelease === null) {
            return $state;
        }

        $baseline = $latestRelease->previous_version;

        return $this->writeReleaseNoteReadState([
            ReleaseNoteRead::LAST_SEEN_VERSION => $baseline,
            ReleaseNoteRead::LAST_PROMPTED_VERSION => $baseline,
            ReleaseNoteRead::BOOTSTRAPPED_AT => now(),
        ]);
    }

    public function markReleaseNotesPrompted(string $version): void
    {
        $this->writeReleaseNoteReadState([
            ReleaseNoteRead::LAST_PROMPTED_VERSION => $version,
        ]);
    }

    public function markReleaseNotesRead(string $version): void
    {
        $this->writeReleaseNoteReadState([
            ReleaseNoteRead::LAST_SEEN_VERSION => $version,
            ReleaseNoteRead::LAST_PROMPTED_VERSION => $version,
        ]);
    }

    public function clearReleaseNoteReadState(): void
    {
        $this->releaseNoteRead()->delete();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{last_seen_version: string|null, last_prompted_version: string|null, bootstrapped_at: string|null}
     */
    protected function writeReleaseNoteReadState(array $values): array
    {
        $values[ReleaseNoteRead::BOOTSTRAPPED_AT] ??= now();

        $read = $this->releaseNoteRead()->first();

        if ($read instanceof ReleaseNoteRead) {
            $read->fill($values)->save();

            return $read->toStateArray();
        }

        /** @var ReleaseNoteRead $created */
        $created = $this->releaseNoteRead()->create($values);

        return $created->toStateArray();
    }
}
