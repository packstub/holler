<?php

namespace Packstub\Calls;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Traits\Macroable;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Events\CallAnswered;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\CallStarted;
use Packstub\Calls\Exceptions\CalleeBusy;
use Packstub\Calls\Exceptions\CallHasEnded;
use Packstub\Calls\Exceptions\CallNotAllowed;
use Packstub\Calls\Exceptions\CallNotRinging;
use Packstub\Calls\Http\CallsController;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallSignal;

/**
 * The signaling core: who calls whom, which window holds each side, and the
 * offer, answer and ICE candidates relayed between the two browsers. No media
 * ever comes through here. The HTTP endpoints in CallsController are thin
 * wrappers over these methods; a plain app can call them directly.
 */
class Calls
{
    use Macroable;

    /**
     * Register the seven JSON routes under `{prefix}/`, named `{as}poll`, `{as}store`…
     *
     * @param  array<int, string> | string  $middleware
     */
    public function routes(string $prefix = 'calls', array|string $middleware = 'auth', string $as = 'calls.'): void
    {
        Route::prefix($prefix)->name($as)->middleware($middleware)->group(function (): void {
            Route::get('poll', [CallsController::class, 'poll'])->name('poll');
            Route::post('/', [CallsController::class, 'store'])->name('store');
            Route::post('{call}/answer', [CallsController::class, 'answer'])->name('answer');
            Route::post('{call}/decline', [CallsController::class, 'decline'])->name('decline');
            Route::post('{call}/hangup', [CallsController::class, 'hangup'])->name('hangup');
            Route::post('{call}/signals', [CallsController::class, 'signal'])->name('signal');
            Route::post('{call}/leave', [CallsController::class, 'leave'])->name('leave');
        });
    }

    public function context(): CallContext
    {
        return app(CallContext::class);
    }

    public function currentUserId(): ?string
    {
        return $this->context()->currentUserId();
    }

    public function findUser(string $id): ?Authenticatable
    {
        return $this->context()->findUser($id);
    }

    /** @return array{id: string, name: string, avatar: ?string} */
    public function who(Authenticatable $user): array
    {
        return $this->context()->who($user);
    }

    // ------------------------------------------------------------------
    // Calls
    // ------------------------------------------------------------------

    /**
     * Ring someone. Any call the caller still has open is cancelled first.
     *
     * @param  ?string  $tab  the caller's window, which holds the caller side from the start
     *
     * @throws CallNotAllowed yourself, nobody, or someone the context says no to
     * @throws CalleeBusy the callee is on another call
     */
    public function start(Authenticatable $caller, Authenticatable|string|null $callee, ?string $tab = null): Call
    {
        $callee = is_string($callee) ? $this->findUser($callee) : $callee;
        $me = (string) $caller->getAuthIdentifier();

        if (! $callee || (string) $callee->getAuthIdentifier() === $me || ! $this->context()->canCall($caller, $callee)) {
            throw new CallNotAllowed(__('calls::messages.cannot_call'));
        }

        $this->openCallsOf($me)->each(fn (Call $open) => $this->end($open, 'cancelled'));

        if ($this->openCallsOf((string) $callee->getAuthIdentifier())->isNotEmpty()) {
            throw new CalleeBusy(__('calls::messages.busy'));
        }

        $call = Call::query()->create([
            'caller_id' => $me,
            'callee_id' => (string) $callee->getAuthIdentifier(),
            'status' => Call::RINGING,
            'caller_tab' => $tab,
            'caller_seen_at' => $tab ? now() : null,
        ]);

        CallStarted::dispatch($call);

        return $call;
    }

    /**
     * The callee joins; the call becomes active.
     *
     * @throws CallNotRinging not the callee, or the call stopped ringing
     */
    public function answer(Call $call, string $userId, ?string $tab = null): Call
    {
        if ($call->callee_id !== $userId || ! $call->isRinging()) {
            throw new CallNotRinging(__('calls::messages.not_ringing'));
        }

        $call->forceFill([
            'status' => Call::ACTIVE,
            'answered_at' => now(),
            'callee_tab' => $tab,
            'callee_seen_at' => $tab ? now() : null,
        ])->save();

        CallAnswered::dispatch($call);

        return $call;
    }

    /** The callee says no (declined); a caller using this endpoint cancels. */
    public function decline(Call $call, string $userId): Call
    {
        $this->end($call, $call->callee_id === $userId ? 'declined' : 'cancelled');

        return $call;
    }

    /** Either side ends it: cancelled or declined while ringing, hung up once it ran. */
    public function hangup(Call $call, string $userId): Call
    {
        $this->end($call, $call->isRinging() ? ($call->caller_id === $userId ? 'cancelled' : 'declined') : 'hung_up');

        return $call;
    }

    /** End a call with a reason (idempotent): its signals are deleted and CallEnded fires once. */
    public function end(Call $call, string $reason): void
    {
        if ($call->isEnded()) {
            return;
        }

        $call->end($reason);

        CallEnded::dispatch($call);
    }

    /**
     * Queue one signaling message (offer, answer, ice, reset) for the other side.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CallHasEnded
     */
    public function signal(Call $call, string $fromUserId, string $type, array $payload): CallSignal
    {
        if ($call->isEnded()) {
            throw new CallHasEnded(__('calls::messages.ended'));
        }

        return $call->signals()->create([
            'to_user_id' => $call->peerOf($fromUserId),
            'type' => $type,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /** Mark this user's stale calls: ringing past `ring_timeout` as missed, active with a side unseen past `reconnect_timeout` as dropped. */
    public function expire(string $userId): void
    {
        Call::query()->where('status', Call::RINGING)
            ->where('created_at', '<', now()->subSeconds((int) config('packstub-calls.ring_timeout', 45)))
            ->where($this->involving($userId))
            ->each(fn (Call $stale) => $this->end($stale, 'missed'));

        $deadline = now()->subSeconds((int) config('packstub-calls.reconnect_timeout', 15));

        Call::query()->where('status', Call::ACTIVE)->where($this->involving($userId))
            ->where(fn (Builder $query) => $query->where('caller_seen_at', '<', $deadline)->orWhere('callee_seen_at', '<', $deadline))
            ->each(fn (Call $lost) => $this->end($lost, 'dropped'));
    }

    /**
     * What a window learns on each poll: the call it is in (held by it from then
     * on when it asks to `hold`, which is also its heartbeat) or any open call of
     * mine for a page that just loaded, an incoming call, and my new signals.
     *
     * @return array{call: ?array<string, mixed>, incoming: ?array<string, mixed>, signals: array<int, array<string, mixed>>, cursor: int}
     */
    public function poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $hold = false): array
    {
        $this->expire($me);

        $open = $this->openCallsOf($me);

        // The call this window names (so it also learns when that call ended), or else
        // any open call the server still has me in, for a page that just loaded.
        $current = $currentId
            ? Call::query()->find($currentId)
            : $open->first(fn (Call $call) => $call->isActive() || $call->caller_id === $me);

        if ($current && ! $current->involves($me)) {
            $current = null;
        }

        $held = false;

        if ($current && $currentId && $tab && $hold && ! $current->isEnded()) {
            $held = $current->claim($me, $tab, $reclaimed);

            if ($reclaimed) {
                // A fresh page starts after the signals meant for the old connection (its own
                // session check covers the rest); they stay in case the old window is still alive.
                $after = max($after, (int) CallSignal::query()->max('id'));
            }
        }

        $incoming = $open->first(fn (Call $call) => $call->isRinging() && $call->callee_id === $me && ! $current?->is($call));

        // Signals only flow to the window that holds the side: a callee gets the offer once they answer.
        $signals = $held
            ? CallSignal::query()->where('to_user_id', $me)->where('call_id', $current->getKey())->where('id', '>', $after)->orderBy('id')->limit(100)->get()
            : collect();

        return [
            'call' => $current ? $this->present($current, $me, $tab) : null,
            'incoming' => $incoming ? $this->present($incoming, $me, $tab) : null,
            'signals' => $signals->map(fn (CallSignal $signal) => [
                'id' => $signal->id,
                'call' => $signal->call_id,
                'type' => $signal->type,
                'payload' => $signal->payload,
            ])->all(),
            'cursor' => (int) ($signals->max('id') ?? $after),
        ];
    }

    /** The ringing and active calls this user is in, newest first. */
    public function openCallsOf(string $userId): Collection
    {
        return Call::query()->where('status', '!=', Call::ENDED)->where($this->involving($userId))->latest('id')->get();
    }

    /**
     * A call as one side sees it.
     *
     * @return array<string, mixed>
     */
    public function present(Call $call, string $me, ?string $tab = null): array
    {
        $peer = $this->findUser($call->peerOf($me));
        $side = $call->sideOf($me);
        $peerSide = $side === 'caller' ? 'callee' : 'caller';

        return [
            'id' => $call->id,
            'status' => $call->status,
            'end_reason' => $call->end_reason,
            'role' => $side,
            'peer' => $peer ? $this->who($peer) : ['id' => $call->peerOf($me), 'name' => __('calls::messages.someone'), 'avatar' => null],
            'answered_at' => $call->answered_at?->toIso8601String(),
            // Another window of mine holds my side.
            'held' => $call->isHeld($side) && $call->{"{$side}_tab"} !== $tab,
            'peer_held' => $call->isHeld($peerSide),
            'peer_seen_ago' => $call->{"{$peerSide}_seen_at"} ? max(0, (int) $call->{"{$peerSide}_seen_at"}->diffInSeconds(now())) : null,
        ];
    }

    protected function involving(string $userId): \Closure
    {
        return fn (Builder $query) => $query->where('caller_id', $userId)->orWhere('callee_id', $userId);
    }
}
