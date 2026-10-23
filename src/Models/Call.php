<?php

namespace Packstub\Calls\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $caller_id
 * @property string $callee_id
 * @property string $status
 * @property ?string $end_reason
 * @property ?string $caller_tab
 * @property ?string $callee_tab
 * @property ?Carbon $caller_seen_at
 * @property ?Carbon $callee_seen_at
 * @property ?Carbon $answered_at
 * @property ?Carbon $ended_at
 * @property Carbon $created_at
 */
class Call extends Model
{
    public const RINGING = 'ringing';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    /** Seconds without a poll after which a window no longer counts as holding its side. */
    public const HOLD_SECONDS = 5;

    protected $guarded = [];

    protected $casts = [
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'caller_seen_at' => 'datetime',
        'callee_seen_at' => 'datetime',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(CallSignal::class);
    }

    public function involves(string $userId): bool
    {
        return $this->caller_id === $userId || $this->callee_id === $userId;
    }

    public function peerOf(string $userId): string
    {
        return $this->caller_id === $userId ? $this->callee_id : $this->caller_id;
    }

    /** 'caller' or 'callee'. */
    public function sideOf(string $userId): string
    {
        return $this->caller_id === $userId ? 'caller' : 'callee';
    }

    public function isRinging(): bool
    {
        return $this->status === self::RINGING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isEnded(): bool
    {
        return $this->status === self::ENDED;
    }

    /** Whether a live window holds this side: a tab is set and polled within HOLD_SECONDS. */
    public function isHeld(string $side): bool
    {
        return $this->{"{$side}_tab"} !== null
            && $this->{"{$side}_seen_at"}?->gt(now()->subSeconds(self::HOLD_SECONDS)) === true;
    }

    /**
     * Give this user's side to a window: free, already its own, or left by a window
     * that stopped polling. One UPDATE, so two windows polling at once cannot both win.
     * Returns whether the window holds the side now; sets $reclaimed when it took
     * over from another (or no) window.
     */
    public function claim(string $userId, string $tab, ?bool &$reclaimed = null): bool
    {
        $side = $this->sideOf($userId);
        $previous = $this->{"{$side}_tab"};
        $heldBefore = $this->{"{$side}_seen_at"} !== null;

        $claimed = static::query()->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull("{$side}_tab")
                ->orWhere("{$side}_tab", $tab)
                ->orWhere("{$side}_seen_at", '<', now()->subSeconds(self::HOLD_SECONDS)))
            ->update(["{$side}_tab" => $tab, "{$side}_seen_at" => now()]) === 1;

        if ($claimed) {
            $this->forceFill(["{$side}_tab" => $tab, "{$side}_seen_at" => now()])->syncOriginal();
        }

        // Taking over from another window (or one that left) is a reclaim; a side nobody ever held is not.
        $reclaimed = $claimed && $heldBefore && $previous !== $tab;

        return $claimed;
    }

    /** The window lets go of its side (the page unloads); the last-seen time stays for the timeout. */
    public function release(string $userId, string $tab): void
    {
        $side = $this->sideOf($userId);

        if ($this->{"{$side}_tab"} === $tab) {
            $this->forceFill(["{$side}_tab" => null])->save();
        }
    }

    /** Seconds the call ran, null for a call that was never answered. */
    public function duration(): ?int
    {
        if (! $this->answered_at) {
            return null;
        }

        return (int) $this->answered_at->diffInSeconds($this->ended_at ?? now());
    }

    public function end(string $reason): void
    {
        if ($this->isEnded()) {
            return;
        }

        $this->forceFill(['status' => self::ENDED, 'end_reason' => $reason, 'ended_at' => now()])->save();
        $this->signals()->delete();
    }
}
