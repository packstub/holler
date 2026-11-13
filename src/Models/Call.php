<?php

namespace Packstub\Holler\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Packstub\Holler\Models\Concerns\UsesCallsConnection;

/**
 * @property int $id
 * @property string $caller_id
 * @property string $callee_id the first person called; everyone is in `participants`
 * @property string $kind `call`, or the kind another package gave its calls
 * @property string $scope whose user ids these are (the context's guard)
 * @property string $status
 * @property ?string $end_reason
 * @property ?Carbon $answered_at
 * @property ?Carbon $ended_at
 * @property Carbon $created_at
 * @property-read Collection<int, CallParticipant> $participants
 */
class Call extends Model
{
    use UsesCallsConnection;

    protected $table = 'holler_calls';

    /** The kind of the calls the widgets make and draw. */
    public const KIND = 'call';

    public const RINGING = 'ringing';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    /** Seconds without a poll after which a window no longer counts as holding its side. */
    public const CLAIM_SECONDS = 5;

    protected $guarded = [];

    protected $casts = [
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(CallSignal::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class)->orderBy('seat');
    }

    /** This person's record on the call, whatever their status; null for a bystander. */
    public function participant(string $userId): ?CallParticipant
    {
        return $this->participants->first(fn (CallParticipant $participant) => $participant->user_id === $userId);
    }

    /** Whether this person is (or was) on the call. */
    public function involves(string $userId): bool
    {
        return $this->participant($userId) !== null;
    }

    /** Everyone still on the call (ringing or in), lowest seat first. */
    public function live(): Collection
    {
        return $this->participants->filter(fn (CallParticipant $participant) => $participant->isLive())->values();
    }

    /** Everyone on the call but this person, lowest seat first, gone ones included. */
    public function peersOf(string $userId): Collection
    {
        return $this->participants->reject(fn (CallParticipant $participant) => $participant->user_id === $userId)->values();
    }

    /** The other side of a 1-on-1 call: the first live other person, or the first other person. */
    public function peerOf(string $userId): ?string
    {
        $peers = $this->peersOf($userId);

        return ($peers->first(fn (CallParticipant $peer) => $peer->isLive()) ?? $peers->first())?->user_id;
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
