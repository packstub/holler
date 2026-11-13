<?php

namespace Packstub\Holler\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Packstub\Holler\Models\Concerns\UsesCallsConnection;

/**
 * One person on a call: their seat (the order they were invited in; the lower
 * seat offers), whether they are ringing, in, away with the call on hold, or
 * gone, and which browser window holds their side.
 *
 * @property int $id
 * @property int $call_id
 * @property string $user_id
 * @property ?string $name set for a participant made in code (`guest:<uuid>`)
 * @property ?string $avatar
 * @property int $seat
 * @property string $status
 * @property ?string $tab
 * @property ?Carbon $seen_at
 * @property ?Carbon $invited_at
 * @property ?Carbon $joined_at
 * @property ?Carbon $left_at
 */
class CallParticipant extends Model
{
    use UsesCallsConnection;

    protected $table = 'holler_call_participants';

    public const RINGING = 'ringing';

    public const JOINED = 'joined';

    public const LEFT = 'left';

    public const DECLINED = 'declined';

    public const MISSED = 'missed';

    public const DROPPED = 'dropped';

    /** Stepped out with the call on hold: still on it, with no window and no media until they resume. */
    public const ON_HOLD = 'on_hold';

    /** The statuses of someone still on the call. */
    public const LIVE = [self::RINGING, self::JOINED, self::ON_HOLD];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'seat' => 'integer',
        'seen_at' => 'datetime',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function isRinging(): bool
    {
        return $this->status === self::RINGING;
    }

    public function isJoined(): bool
    {
        return $this->status === self::JOINED;
    }

    /** Whether this person put the call on hold (see `Holler::hold()`). */
    public function isOnHold(): bool
    {
        return $this->status === self::ON_HOLD;
    }

    /** Ringing, in, or away with the call on hold: still on the call. */
    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** Whether a live window holds this side: a tab is set and polled within HOLD_SECONDS. */
    public function isHeld(): bool
    {
        return $this->tab !== null && $this->seen_at?->gt(now()->subSeconds(Call::HOLD_SECONDS)) === true;
    }

    /** Seconds since this side's window last polled, null if it never did. */
    public function seenAgo(): ?int
    {
        return $this->seen_at ? max(0, (int) $this->seen_at->diffInSeconds(now())) : null;
    }

    /**
     * Give this side to a window: free, already its own, or left by a window that
     * stopped polling. One UPDATE, so two windows polling at once cannot both win.
     * Returns whether the window holds the side now; sets $reclaimed when it took
     * over from another (or no) window.
     */
    public function claim(string $tab, ?bool &$reclaimed = null): bool
    {
        $previous = $this->tab;
        $heldBefore = $this->seen_at !== null;

        $claimed = static::query()->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('tab')
                ->orWhere('tab', $tab)
                ->orWhere('seen_at', '<', now()->subSeconds(Call::HOLD_SECONDS)))
            ->update(['tab' => $tab, 'seen_at' => now()]) === 1;

        if ($claimed) {
            $this->forceFill(['tab' => $tab, 'seen_at' => now()])->syncOriginal();
        }

        // Taking over from another window (or one that left) is a reclaim; a side nobody ever held is not.
        $reclaimed = $claimed && $heldBefore && $previous !== $tab;

        return $claimed;
    }

    /** The window lets go of its side (the page unloads); the last-seen time stays for the timeout. */
    public function release(string $tab): void
    {
        if ($this->tab === $tab) {
            $this->forceFill(['tab' => null])->save();
        }
    }
}
