<?php

namespace Medalink\AppVersion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per user: which release version they last read, which one they
 * were last prompted about (banner shown or dismissed), and when the row was
 * bootstrapped.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $last_seen_version
 * @property string|null $last_prompted_version
 * @property Carbon|null $bootstrapped_at
 */
class ReleaseNoteRead extends Model
{
    use HasUlids;

    public const string LAST_SEEN_VERSION = 'last_seen_version';

    public const string LAST_PROMPTED_VERSION = 'last_prompted_version';

    public const string BOOTSTRAPPED_AT = 'bootstrapped_at';

    protected $table = 'release_note_reads';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            self::BOOTSTRAPPED_AT => 'datetime',
        ];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * @return array{last_seen_version: string|null, last_prompted_version: string|null, bootstrapped_at: string|null}
     */
    public function toStateArray(): array
    {
        return [
            self::LAST_SEEN_VERSION => $this->last_seen_version,
            self::LAST_PROMPTED_VERSION => $this->last_prompted_version,
            self::BOOTSTRAPPED_AT => $this->bootstrapped_at?->toIso8601String(),
        ];
    }
}
