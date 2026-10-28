<?php

namespace Packstub\Calls;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Traits\Macroable;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Events\CallAnswered;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\CallStarted;
use Packstub\Calls\Events\ParticipantJoined;
use Packstub\Calls\Events\ParticipantLeft;
use Packstub\Calls\Exceptions\CalleeBusy;
use Packstub\Calls\Exceptions\CallFull;
use Packstub\Calls\Exceptions\CallHasEnded;
use Packstub\Calls\Exceptions\CallNotAllowed;
use Packstub\Calls\Exceptions\CallNotRinging;
use Packstub\Calls\Http\AssetController;
use Packstub\Calls\Http\CallsController;
use Packstub\Calls\Http\PopOutController;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;
use Packstub\Calls\Models\CallSignal;

/**
 * The signaling core: who calls whom, who is on a call and at which seat, which
 * window holds each person's side, and the offer, answer and ICE candidates
 * relayed between the browsers. No media ever comes through here. The HTTP
 * endpoints in CallsController are thin wrappers over these methods; a plain
 * app can call them directly.
 *
 * A call has a participant per person: the caller, joined from the start at seat
 * 0, and every invitee ringing at the next seat. Between two participants the
 * lower seat makes the WebRTC offer. Someone who hangs up leaves the call for the
 * others; the call ends once fewer than two people are left on it.
 */
class Calls
{
    use Macroable;

    /**
     * Register the eight JSON routes and the widget's pop-out page under `{prefix}/`, named `{as}poll`, `{as}store`…
     *
     * @param  array<int, string> | string  $middleware
     * @param  bool  $window  also the widget's pop-out page, `GET {prefix}/window`
     */
    public function routes(string $prefix = 'calls', array|string $middleware = 'auth', string $as = 'calls.', bool $window = true): void
    {
        Route::prefix($prefix)->name($as)->middleware($middleware)->group(function () use ($window): void {
            Route::get('poll', [CallsController::class, 'poll'])->name('poll');
            Route::post('/', [CallsController::class, 'store'])->name('store');
            Route::post('{call}/answer', [CallsController::class, 'answer'])->name('answer');
            Route::post('{call}/decline', [CallsController::class, 'decline'])->name('decline');
            Route::post('{call}/hangup', [CallsController::class, 'hangup'])->name('hangup');
            Route::post('{call}/invite', [CallsController::class, 'invite'])->name('invite');
            Route::post('{call}/signals', [CallsController::class, 'signal'])->name('signal');
            Route::post('{call}/leave', [CallsController::class, 'leave'])->name('leave');
            // The pop-out page of `<x-calls::widget />`; a package with a window of its own passes `window: false`.
            if ($window) {
                Route::get('window', PopOutController::class)->name('window');
            }
        });
    }

    /** The URL of a file the package serves to the browser: `index.js` (the engine), `widget.js`, `widget.css`. */
    public function asset(string $file): string
    {
        return route('calls.asset', ['file' => $file]).'?v='.AssetController::version($file);
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

    /** People on one call, the caller included (never below two). */
    public function maxParticipants(): int
    {
        return max(2, $this->context()->maxParticipants());
    }

    // ------------------------------------------------------------------
    // Calls
    // ------------------------------------------------------------------

    /**
     * Ring one person, or several at once. Any call the caller is still on is
     * left first (and ends if that leaves fewer than two people on it).
     *
     * @param  Authenticatable|string|array<int, Authenticatable|string>|null  $callees
     * @param  ?string  $tab  the caller's window, which holds the caller's side from the start
     *
     * @throws CallNotAllowed yourself, nobody, or someone the context says no to
     * @throws CallFull more people than `max_participants`
     * @throws CalleeBusy a callee is on another call
     */
    public function start(Authenticatable $caller, Authenticatable|string|array|null $callees, ?string $tab = null): Call
    {
        $me = (string) $caller->getAuthIdentifier();
        $people = $this->resolveCallees($caller, is_array($callees) ? $callees : [$callees]);

        if (count($people) + 1 > $this->maxParticipants()) {
            throw new CallFull(__('calls::messages.full', ['max' => $this->maxParticipants()]));
        }

        $this->openCallsOf($me)->each(fn (Call $open) => $this->withdraw($open, $open->participant($me)));

        foreach ($people as $person) {
            if ($this->openCallsOf((string) $person->getAuthIdentifier())->isNotEmpty()) {
                throw new CalleeBusy(__('calls::messages.busy'));
            }
        }

        $call = DB::transaction(function () use ($me, $people, $tab): Call {
            $call = Call::query()->create([
                'caller_id' => $me,
                'callee_id' => (string) $people[0]->getAuthIdentifier(),
                'status' => Call::RINGING,
            ]);

            $call->participants()->create([
                'user_id' => $me,
                'seat' => 0,
                'status' => CallParticipant::JOINED,
                'tab' => $tab,
                'seen_at' => $tab ? now() : null,
                'invited_at' => now(),
                'joined_at' => now(),
            ]);

            foreach ($people as $index => $person) {
                $call->participants()->create([
                    'user_id' => (string) $person->getAuthIdentifier(),
                    'seat' => $index + 1,
                    'status' => CallParticipant::RINGING,
                    'invited_at' => now(),
                ]);
            }

            return $call->load('participants');
        });

        CallStarted::dispatch($call);

        return $call;
    }

    /**
     * Add someone to a call I am on. They ring; the people already on the call
     * offer to them once they answer.
     *
     * @throws CallHasEnded
     * @throws CallNotAllowed not on the call yet, yourself, nobody, someone already on it, or someone the context says no to
     * @throws CallFull
     * @throws CalleeBusy
     */
    public function invite(Call $call, string $byUserId, Authenticatable|string|null $callee): Call
    {
        if ($call->isEnded()) {
            throw new CallHasEnded(__('calls::messages.ended'));
        }

        $by = $call->participant($byUserId);
        $inviter = $by?->isJoined() ? $this->findUser($byUserId) : null;

        if (! $inviter) {
            throw new CallNotAllowed(__('calls::messages.join_first'));
        }

        [$person] = $this->resolveCallees($inviter, [$callee]);
        $personId = (string) $person->getAuthIdentifier();
        $existing = $call->participant($personId);

        if ($existing?->isLive()) {
            throw new CallNotAllowed(__('calls::messages.cannot_call'));
        }

        if ($call->live()->count() + 1 > $this->maxParticipants()) {
            throw new CallFull(__('calls::messages.full', ['max' => $this->maxParticipants()]));
        }

        if ($this->openCallsOf($personId)->isNotEmpty()) {
            throw new CalleeBusy(__('calls::messages.busy'));
        }

        // The seat is read and taken under a lock on the call: two invites at once must
        // not share one (between two participants the lower seat offers).
        DB::transaction(function () use ($call, $existing, $personId): void {
            Call::query()->whereKey($call->getKey())->lockForUpdate()->first();

            $attributes = [
                'seat' => (int) CallParticipant::query()->where('call_id', $call->getKey())->max('seat') + 1,
                'status' => CallParticipant::RINGING,
                'tab' => null,
                'seen_at' => null,
                'invited_at' => now(),
                'joined_at' => null,
                'left_at' => null,
            ];

            // Someone who left or declined earlier rings again, at a new seat.
            if ($existing) {
                $existing->forceFill($attributes)->save();
            } else {
                $call->participants()->create(['user_id' => $personId, ...$attributes]);
            }
        });

        return $call->load('participants');
    }

    /**
     * Someone who was rung joins; the call becomes active the first time that happens.
     *
     * @throws CallNotRinging not rung, or the call ended
     */
    public function answer(Call $call, string $userId, ?string $tab = null): Call
    {
        $me = $call->participant($userId);

        if ($call->isEnded() || ! $me?->isRinging()) {
            throw new CallNotRinging(__('calls::messages.not_ringing'));
        }

        $me->forceFill([
            'status' => CallParticipant::JOINED,
            'tab' => $tab,
            'seen_at' => $tab ? now() : null,
            'joined_at' => now(),
        ])->save();

        $first = $call->isRinging();

        if ($first) {
            $call->forceFill(['status' => Call::ACTIVE, 'answered_at' => now()])->save();
        }

        ParticipantJoined::dispatch($call, $me);

        if ($first) {
            CallAnswered::dispatch($call);
        }

        return $call;
    }

    /** Someone rung says no (declined); someone already on the call leaves it. */
    public function decline(Call $call, string $userId): Call
    {
        $this->withdraw($call, $call->participant($userId));

        return $call;
    }

    /** Leave the call: it goes on for the others, and ends once fewer than two people are left. */
    public function hangup(Call $call, string $userId): Call
    {
        $this->withdraw($call, $call->participant($userId));

        return $call;
    }

    /**
     * A participant is gone (left, declined, missed or dropped); the call ends once
     * fewer than two people, or nobody who joined, are left on it. The caller
     * giving up before anyone joined cancels the call for everyone.
     */
    public function withdraw(Call $call, ?CallParticipant $participant, ?string $status = null): void
    {
        if (! $participant || $call->isEnded() || ! $participant->isLive()) {
            return;
        }

        $participant->forceFill([
            'status' => $status ?? ($participant->isJoined() ? CallParticipant::LEFT : CallParticipant::DECLINED),
            'tab' => null,
            'left_at' => now(),
        ])->save();

        ParticipantLeft::dispatch($call, $participant);

        $live = $call->live();
        $joined = $live->filter(fn (CallParticipant $other) => $other->isJoined());

        if ($call->isRinging() && $participant->user_id === $call->caller_id) {
            $this->end($call, 'cancelled');

            return;
        }

        if ($live->count() < 2 || $joined->isEmpty()) {
            $this->end($call, match (true) {
                $call->isActive() => $participant->status === CallParticipant::DROPPED ? 'dropped' : 'hung_up',
                $participant->status === CallParticipant::MISSED => 'missed',
                default => 'declined',
            });
        }
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
     * Queue one signaling message (offer, answer, ice, reset, state) for one
     * participant, or for everyone else on the call when `$to` is null. Returns
     * the last signal written, null when nobody else is left to receive it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CallHasEnded
     * @throws CallNotAllowed `$to` is not on the call
     */
    public function signal(Call $call, string $fromUserId, string $type, array $payload, ?string $to = null): ?CallSignal
    {
        if ($call->isEnded()) {
            throw new CallHasEnded(__('calls::messages.ended'));
        }

        $recipients = $call->live()->filter(fn (CallParticipant $participant) => $participant->user_id !== $fromUserId);

        if ($to !== null) {
            if (! $call->involves($to)) {
                throw new CallNotAllowed(__('calls::messages.not_on_call'));
            }

            $recipients = $recipients->filter(fn (CallParticipant $participant) => $participant->user_id === $to);
        }

        $signal = null;

        foreach ($recipients as $recipient) {
            $signal = $call->signals()->create([
                'from_user_id' => $fromUserId,
                'to_user_id' => $recipient->user_id,
                'type' => $type,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        }

        return $signal;
    }

    /**
     * Mark this user's stale calls: someone rung past `ring_timeout` is missed (and a
     * call nobody answered ends as missed), someone away past `reconnect_timeout`
     * is dropped (and a call that leaves fewer than two people ends as dropped).
     */
    public function expire(string $userId): void
    {
        $ringDeadline = now()->subSeconds((int) config('packstub-calls.ring_timeout', 45));
        $dropDeadline = now()->subSeconds((int) config('packstub-calls.reconnect_timeout', 15));

        foreach ($this->openCallsOf($userId) as $call) {
            foreach ($call->live() as $participant) {
                if ($participant->isRinging() && $participant->invited_at?->lt($ringDeadline)) {
                    $this->withdraw($call, $participant, CallParticipant::MISSED);
                } elseif ($participant->isJoined() && $call->isActive() && $participant->seen_at?->lt($dropDeadline)) {
                    $this->withdraw($call, $participant, CallParticipant::DROPPED);
                }
            }
        }
    }

    /**
     * What a window learns on each poll: the call it is on (held by it from then
     * on when it asks to `hold`, which is also its heartbeat) or any open call of
     * mine for a page that just loaded, a call ringing me, and my new signals.
     *
     * @return array{call: ?array<string, mixed>, incoming: ?array<string, mixed>, signals: array<int, array<string, mixed>>, cursor: int}
     */
    public function poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $hold = false): array
    {
        $this->expire($me);

        $open = $this->openCallsOf($me);

        // The call this window names (so it also learns when that call ended), or else
        // any open call the server still has me on, for a page that just loaded.
        $current = $currentId
            ? Call::query()->with('participants')->find($currentId)
            : $open->first(fn (Call $call) => $call->participant($me)?->isJoined());

        // Not mine, or no longer mine: someone who left a call that goes on for the others
        // is done with it (an ended call is still shown, so the window learns the reason).
        if ($current && (! $current->involves($me) || (! $current->isEnded() && ! $current->participant($me)->isLive()))) {
            $current = null;
        }

        $held = false;

        if ($current && $currentId && $tab && $hold && ! $current->isEnded() && $current->participant($me)->isLive()) {
            $held = $current->participant($me)->claim($tab, $reclaimed);

            if ($reclaimed) {
                // A fresh page starts after the signals meant for the old connection (its own
                // session check covers the rest); they stay in case the old window is still alive.
                $after = max($after, (int) CallSignal::query()->max('id'));
            }
        }

        $incoming = $open->first(fn (Call $call) => $call->participant($me)?->isRinging() && ! $current?->is($call));

        // Signals only flow to the window that holds the side: a callee gets the offers once they answer.
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
                'from' => $signal->from_user_id,
            ])->all(),
            'cursor' => (int) ($signals->max('id') ?? $after),
        ];
    }

    /** The ringing and active calls this user is still on, newest first. */
    public function openCallsOf(string $userId): Collection
    {
        return Call::query()->with('participants')->where('status', '!=', Call::ENDED)->where($this->involving($userId))->latest('id')->get();
    }

    /**
     * A call as one person sees it: everyone else on it (`peers`, with seat, status
     * and heartbeat), my seat, whether I joined, plus `peer`, `peer_held` and
     * `peer_seen_ago` for the first peer, which is all a 1-on-1 client reads.
     *
     * @return array<string, mixed>
     */
    public function present(Call $call, string $me, ?string $tab = null): array
    {
        $mine = $call->participant($me);
        $people = $this->whoAre($call->peersOf($me)->pluck('user_id')->all());
        $peers = $call->peersOf($me)->map(fn (CallParticipant $peer) => [
            ...$people[$peer->user_id],
            'seat' => $peer->seat,
            'status' => $peer->status,
            'held' => $peer->isHeld(),
            'seen_ago' => $peer->seenAgo(),
        ])->values();
        $first = $peers->first(fn (array $peer) => in_array($peer['status'], CallParticipant::LIVE, true)) ?? $peers->first();

        return [
            'id' => $call->id,
            'status' => $call->status,
            'end_reason' => $call->end_reason,
            'role' => $call->sideOf($me),
            'seat' => $mine?->seat,
            'joined' => $mine?->isJoined() ?? false,
            'peers' => $peers->all(),
            'peer' => $first ? ['id' => $first['id'], 'name' => $first['name'], 'avatar' => $first['avatar']] : null,
            'answered_at' => $call->answered_at?->toIso8601String(),
            // Another window of mine holds my side.
            'held' => $mine !== null && $mine->isHeld() && $mine->tab !== $tab,
            'peer_held' => $first['held'] ?? false,
            'peer_seen_ago' => $first['seen_ago'] ?? null,
        ];
    }

    /**
     * Name and avatar for each of these people, by id, in one lookup when the context
     * offers `findUsers()` (the shipped contexts do); someone not found is "Someone".
     *
     * @param  array<int, string>  $userIds
     * @return array<string, array{id: string, name: string, avatar: ?string}>
     */
    public function whoAre(array $userIds): array
    {
        $context = $this->context();
        $users = method_exists($context, 'findUsers')
            ? $context->findUsers($userIds)
            : array_filter(array_map(fn (string $id) => $context->findUser($id), $userIds));

        $found = [];

        foreach ($users as $user) {
            $found[(string) $user->getAuthIdentifier()] = $this->who($user);
        }

        $people = [];

        foreach ($userIds as $userId) {
            $people[$userId] = $found[$userId] ?? ['id' => $userId, 'name' => __('calls::messages.someone'), 'avatar' => null];
        }

        return $people;
    }

    /**
     * The people to ring: found, not the caller, allowed by the context, each once.
     *
     * @param  array<int, Authenticatable|string|null>  $callees
     * @return array<int, Authenticatable>
     *
     * @throws CallNotAllowed
     */
    protected function resolveCallees(Authenticatable $caller, array $callees): array
    {
        $me = (string) $caller->getAuthIdentifier();
        $people = [];

        foreach ($callees as $callee) {
            $user = is_string($callee) || is_int($callee) ? $this->findUser((string) $callee) : $callee;

            if (! $user instanceof Authenticatable || (string) $user->getAuthIdentifier() === $me || ! $this->context()->canCall($caller, $user)) {
                throw new CallNotAllowed(__('calls::messages.cannot_call'));
            }

            $people[(string) $user->getAuthIdentifier()] = $user;
        }

        if ($people === []) {
            throw new CallNotAllowed(__('calls::messages.cannot_call'));
        }

        return array_values($people);
    }

    protected function involving(string $userId): \Closure
    {
        return fn (Builder $query) => $query->whereHas('participants', fn (Builder $participants) => $participants
            ->where('user_id', $userId)
            ->whereIn('status', CallParticipant::LIVE));
    }
}
