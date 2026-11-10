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
use Packstub\Calls\Exceptions\SignalTooLarge;
use Packstub\Calls\Exceptions\TooManySignals;
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
     * Register the nine JSON routes and the widget's pop-out page under `{prefix}/`, named `{as}poll`, `{as}store`…
     *
     * @param  array<int, string> | string | null  $middleware  null: `auth`, on the `guard` from the config when one is set
     * @param  bool  $window  also the widget's pop-out page, `GET {prefix}/window`
     */
    public function routes(string $prefix = 'calls', array|string|null $middleware = null, string $as = 'calls.', bool $window = true): void
    {
        $guard = config('packstub-calls.guard');
        $middleware ??= filled($guard) ? 'auth:'.$guard : 'auth';

        Route::prefix($prefix)->name($as)->middleware($middleware)->group(function () use ($window): void {
            Route::get('poll', [CallsController::class, 'poll'])->name('poll');
            // Asked when a call starts, so short-lived TURN credentials are fresh for each call.
            Route::get('ice-servers', [CallsController::class, 'ice'])->name('ice');
            Route::post('/', [CallsController::class, 'store'])->name('store');
            Route::post('{call}/answer', [CallsController::class, 'answer'])->name('answer')->whereNumber('call');
            Route::post('{call}/decline', [CallsController::class, 'decline'])->name('decline')->whereNumber('call');
            Route::post('{call}/hangup', [CallsController::class, 'hangup'])->name('hangup')->whereNumber('call');
            Route::post('{call}/invite', [CallsController::class, 'invite'])->name('invite')->whereNumber('call');
            Route::post('{call}/signals', [CallsController::class, 'signal'])->name('signal')->whereNumber('call');
            Route::post('{call}/leave', [CallsController::class, 'leave'])->name('leave')->whereNumber('call');
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

    /**
     * What the browsers pass to `new RTCPeerConnection()`: the ICE servers and the
     * transport policy (`relay` keeps everyone's addresses behind the TURN server).
     *
     * @return array{iceServers: array<int, array<string, mixed>>, iceTransportPolicy: string}
     */
    public function rtcConfiguration(): array
    {
        return [
            'iceServers' => $this->context()->iceServers(),
            'iceTransportPolicy' => config('packstub-calls.ice_transport_policy') === 'relay' ? 'relay' : 'all',
        ];
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
     * left first (and ends if that leaves fewer than two people on it), once
     * every check passed: a refused call leaves the current one alone.
     *
     * A `Participant` is someone made in code (a visitor, say): rung as given,
     * named as given, without asking the context, since the code that made it
     * decided. A string is always a user id, looked up through the context.
     *
     * @param  Authenticatable|Participant|string|array<int, Authenticatable|Participant|string>|null  $callees
     * @param  ?string  $tab  the caller's window, which holds the caller's side from the start
     * @param  string  $kind  `call` for the widgets; a package with its own UI names its kind and polls for it
     * @param  ?string  $scope  whose ids the users are (default: the current context's); a call started outside
     *                          the place its users poll from (a visitor's request ringing a panel user) names it,
     *                          and then takes users or Participants, not ids; `canCall()` and `who()` still run
     *                          in the current context
     *
     * @throws CallNotAllowed yourself, nobody, or someone the context says no to
     * @throws CallFull more people than `max_participants`
     * @throws CalleeBusy a callee is on another call
     */
    public function start(Authenticatable $caller, Authenticatable|Participant|string|array|null $callees, ?string $tab = null, string $kind = Call::KIND, ?string $scope = null): Call
    {
        if (! preg_match('/^[a-z0-9_-]{1,16}$/', $kind)) {
            throw new \InvalidArgumentException('A call kind is 1 to 16 of a-z, 0-9, _ and -.');
        }

        $scope = substr($scope ?? $this->scope(), 0, 64);
        $me = (string) $caller->getAuthIdentifier();
        $list = is_array($callees) ? $callees : [$callees];

        // A string id would be looked up in the current context's guard, not the call's.
        if ($scope !== $this->scope() && collect($list)->contains(fn ($callee) => is_string($callee) || is_int($callee))) {
            throw new CallNotAllowed(__('calls::messages.cannot_call'));
        }

        $people = $this->resolveCallees($caller, $list);

        if (count($people) + 1 > $this->maxParticipants()) {
            throw new CallFull(__('calls::messages.full', ['max' => $this->maxParticipants()]));
        }

        $mine = $this->openCallsOf($me, $scope);

        foreach ($people as $person) {
            $id = static::idOf($person);
            $theirs = $this->openCallsOf($id, $scope);
            // Calls nobody came back to are let go first: someone who closed every window is not busy.
            $this->expireCalls($theirs);

            // A call with me on it does not count when my leaving ends it, or when they only ring
            // on it (two people ringing each other, say); a call they are in with others does.
            $busy = $theirs->contains(fn (Call $call) => ! $call->isEnded() && (
                ! $mine->contains(fn (Call $own) => $own->is($call))
                || ($call->participant($id)?->isJoined() && $call->live()->count() > 2)
            ));

            if ($busy) {
                throw new CalleeBusy(__('calls::messages.busy'));
            }
        }

        $mine->each(fn (Call $open) => $this->withdraw($open, $open->participant($me)));

        $call = $this->transaction(function () use ($caller, $me, $people, $tab, $kind, $scope): Call {
            $call = Call::query()->create([
                'caller_id' => $me,
                'callee_id' => static::idOf($people[0]),
                'kind' => $kind,
                'scope' => $scope,
                'status' => Call::RINGING,
            ]);

            $call->participants()->create([
                'user_id' => $me,
                ...$this->storedName($caller),
                'seat' => 0,
                'status' => CallParticipant::JOINED,
                'tab' => $tab,
                'seen_at' => $tab ? now() : null,
                'invited_at' => now(),
                'joined_at' => now(),
            ]);

            foreach ($people as $index => $person) {
                $call->participants()->create([
                    'user_id' => static::idOf($person),
                    ...$this->storedName($person),
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
        $by = $call->participant($byUserId);
        // A participant made in code (a visitor) is on a call someone else set up for them; they bring nobody in.
        $inviter = $by?->isJoined() && ! Participant::isGuestId($byUserId) ? $this->findUser($byUserId) : null;

        if (! $inviter && ! $call->isEnded()) {
            throw new CallNotAllowed(__('calls::messages.join_first'));
        }

        [$person] = $inviter ? $this->resolveCallees($inviter, [$callee]) : [null];

        // Calls the invitee walked away from, let go before the lock: never two calls locked at once.
        if ($person) {
            $this->expireCalls($this->openCallsOf(static::idOf($person), $call->scope));
        }

        // Every check runs again under a lock on the call: two invites at once must not
        // share a seat (between two participants the lower seat offers), pass the cap
        // together, or both insert the same person.
        $this->transaction(function () use ($call, $byUserId, $person): void {
            // $person is null only on an ended call, refused below.
            $this->lock($call);

            if ($call->isEnded()) {
                throw new CallHasEnded(__('calls::messages.ended'));
            }

            if (! $call->participant($byUserId)?->isJoined()) {
                throw new CallNotAllowed(__('calls::messages.join_first'));
            }

            // A guest agreed to the people on their call, not to whoever those bring in.
            if ($call->participants->contains(fn (CallParticipant $participant) => Participant::isGuestId($participant->user_id))) {
                throw new CallNotAllowed(__('calls::messages.cannot_call'));
            }

            $personId = static::idOf($person);
            $existing = $call->participant($personId);

            if ($existing?->isLive()) {
                throw new CallNotAllowed(__('calls::messages.cannot_call'));
            }

            if ($call->live()->count() + 1 > $this->maxParticipants()) {
                throw new CallFull(__('calls::messages.full', ['max' => $this->maxParticipants()]));
            }

            if ($this->openCallsOf($personId, $call->scope)->isNotEmpty()) {
                throw new CalleeBusy(__('calls::messages.busy'));
            }

            $attributes = [
                'seat' => (int) $call->participants->max('seat') + 1,
                'status' => CallParticipant::RINGING,
                'tab' => null,
                'seen_at' => null,
                'invited_at' => now(),
                'joined_at' => null,
                'left_at' => null,
            ];

            // Someone who left or declined earlier rings again, at a new seat.
            if ($existing) {
                $existing->forceFill([...$attributes, ...$this->storedName($person)])->save();
            } else {
                $call->participants()->create(['user_id' => $personId, ...$attributes, ...$this->storedName($person)]);
            }
        });

        return $call->load('participants');
    }

    /**
     * Someone who was rung joins; the call becomes active the first time that happens.
     *
     * @throws CallNotRinging not rung, or the call ended
     * @throws CalleeBusy already in another call
     */
    public function answer(Call $call, string $userId, ?string $tab = null): Call
    {
        // Under a lock on the call, so an answer and a hang-up (or the ring timeout) at the
        // same moment cannot bring an ended call back as active.
        [$me, $first] = $this->transaction(function () use ($call, $userId, $tab): array {
            $this->lock($call);
            $me = $call->participant($userId);

            if ($call->isEnded() || ! $me?->isRinging()) {
                throw new CallNotRinging(__('calls::messages.not_ringing'));
            }

            if ($this->openCallsOf($userId, $call->scope)->contains(fn (Call $other) => ! $other->is($call) && $other->participant($userId)?->isJoined())) {
                throw new CalleeBusy(__('calls::messages.busy'));
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

            return [$me, $first];
        });

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
     *
     * Runs under a lock on the call and re-reads it first: two polls expiring the
     * same person, or a hang-up racing the timeout, change it (and fire the
     * events) once.
     */
    public function withdraw(Call $call, ?CallParticipant $participant, ?string $status = null): void
    {
        $this->withdrawIf($call, $participant, $status);
    }

    /**
     * Withdraw only when `$still` holds for the participant as stored now: a timeout
     * decided on a copy read before an answer or a heartbeat landed must not act on it.
     *
     * @param  ?\Closure(CallParticipant): bool  $still
     */
    protected function withdrawIf(Call $call, ?CallParticipant $participant, ?string $status = null, ?\Closure $still = null): void
    {
        if (! $participant) {
            return;
        }

        [$left, $reason] = $this->transaction(function () use ($call, $participant, $status, $still): array {
            $this->lock($call);
            $participant = $call->participant($participant->user_id);

            if ($call->isEnded() || ! $participant?->isLive() || ($still && ! $still($participant, $call))) {
                return [null, null];
            }

            $participant->forceFill([
                'status' => $status ?? ($participant->isJoined() ? CallParticipant::LEFT : CallParticipant::DECLINED),
                'tab' => null,
                'left_at' => now(),
            ])->save();

            $live = $call->live();
            $joined = $live->filter(fn (CallParticipant $other) => $other->isJoined());

            $reason = match (true) {
                $call->isRinging() && $participant->user_id === $call->caller_id => 'cancelled',
                $live->count() >= 2 && $joined->isNotEmpty() => null,
                $call->isActive() => $participant->status === CallParticipant::DROPPED ? 'dropped' : 'hung_up',
                $participant->status === CallParticipant::MISSED => 'missed',
                default => 'declined',
            };

            if ($reason) {
                $call->end($reason);
            }

            return [$participant, $reason];
        });

        if ($left) {
            ParticipantLeft::dispatch($call, $left);
        }

        if ($reason) {
            CallEnded::dispatch($call);
        }
    }

    /**
     * End a call with a reason (idempotent): its signals are deleted and CallEnded fires once.
     *
     * @param  string  $reason  up to 16 characters, like the shipped ones (`hung_up`, `failed`…)
     */
    public function end(Call $call, string $reason): void
    {
        if ($reason === '' || strlen($reason) > 16) {
            throw new \InvalidArgumentException('An end reason is 1 to 16 characters.');
        }

        $ended = $this->transaction(function () use ($call, $reason): bool {
            $this->lock($call);

            if ($call->isEnded()) {
                return false;
            }

            $call->end($reason);

            return true;
        });

        if ($ended) {
            CallEnded::dispatch($call);
        }
    }

    /**
     * Queue one signaling message (offer, answer, ice, reset, state) for one
     * participant, or for everyone else on the call when `$to` is null. Returns
     * the last signal written, null when nobody else is left to receive it.
     * Only someone in the call sends: not someone still ringing or gone.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CallHasEnded
     * @throws CallNotAllowed the sender is not in the call, or `$to` is not on it
     * @throws SignalTooLarge the payload is above `max_signal_kb`
     * @throws TooManySignals more than `max_pending_signals` of the sender's are still unread
     */
    public function signal(Call $call, string $fromUserId, string $type, array $payload, ?string $to = null): ?CallSignal
    {
        if ($call->isEnded()) {
            throw new CallHasEnded(__('calls::messages.ended'));
        }

        if (! $call->participant($fromUserId)?->isJoined()) {
            throw new CallNotAllowed(__('calls::messages.not_on_call'));
        }

        $this->assertSignalFits($payload);
        $this->assertRoomForSignals($call, $fromUserId);

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
        $this->expireCalls($this->openCallsOf($userId));
    }

    /**
     * The same for these calls; each one ended here reads as ended afterwards.
     *
     * @param  iterable<int, Call>  $calls
     */
    public function expireCalls(iterable $calls): void
    {
        $ringDeadline = now()->subSeconds((int) config('packstub-calls.ring_timeout', 45));
        $dropDeadline = now()->subSeconds((int) config('packstub-calls.reconnect_timeout', 15));

        // A side that never named a window has not polled since it joined.
        $missed = fn (CallParticipant $participant): bool => $participant->isRinging() && $participant->invited_at?->lt($ringDeadline) === true;
        $dropped = fn (CallParticipant $participant, Call $call): bool => $participant->isJoined() && $call->isActive()
            && ($participant->seen_at ?? $participant->joined_at)?->lt($dropDeadline) === true;

        foreach ($calls as $call) {
            foreach ($call->live() as $participant) {
                // Decided on this copy, checked again on the row under the lock.
                if ($missed($participant)) {
                    $this->withdrawIf($call, $participant, CallParticipant::MISSED, $missed);
                } elseif ($dropped($participant, $call)) {
                    $this->withdrawIf($call, $participant, CallParticipant::DROPPED, $dropped);
                }
            }
        }
    }

    /**
     * What a window learns on each poll: the call it is on (held by it from then
     * on when it asks to `hold`, which is also its heartbeat) or any open call of
     * mine for a page that just loaded, a call ringing me, and my new signals;
     * only calls of `$kind` when given.
     *
     * @return array{call: ?array<string, mixed>, incoming: ?array<string, mixed>, signals: array<int, array<string, mixed>>, cursor: int}
     */
    public function poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $hold = false, ?string $kind = null): array
    {
        $open = $this->openCallsOf($me);
        $this->expireCalls($open);

        // A window polls for the kind it draws: the widget never even hears of another package's calls.
        $open = $open->filter(fn (Call $call) => $kind === null || $call->kind === $kind)->values();

        // The call this window names (so it also learns when that call ended), or else
        // any open call the server still has me on, for a page that just loaded.
        $current = $currentId
            ? ($open->first(fn (Call $call) => $call->id === $currentId) ?? Call::query()->with('participants')->find($currentId))
            : $open->first(fn (Call $call) => ! $call->isEnded() && $call->participant($me)?->isJoined());

        // Not mine, or no longer mine: someone who left a call that goes on for the others
        // is done with it (an ended call is still shown, so the window learns the reason).
        if ($current && (! $current->involves($me) || ! $this->inScope($current, $me) || ($kind !== null && $current->kind !== $kind) || (! $current->isEnded() && ! $current->participant($me)->isLive()))) {
            $current = null;
        }

        $held = false;

        if ($current && $currentId && $tab && $hold && ! $current->isEnded() && $current->participant($me)->isLive()) {
            $held = $current->participant($me)->claim($tab, $reclaimed);

            // The window that holds the side read everything up to its cursor: those signals are done with.
            if ($held && $after > 0) {
                CallSignal::query()->where('call_id', $current->getKey())->where('to_user_id', $me)->where('id', '<=', $after)->delete();
            }

            if ($reclaimed) {
                // A fresh page starts after the signals meant for the old connection (its own
                // session check covers the rest); they stay in case the old window is still alive.
                $after = max($after, (int) CallSignal::query()->where('call_id', $current->getKey())->max('id'));
            }
        }

        $incoming = $open->first(fn (Call $call) => ! $call->isEnded() && $call->participant($me)?->isRinging() && ! $current?->is($call));

        // Signals only flow to the window that holds the side: a callee gets the offers once they answer.
        $signals = $held
            ? CallSignal::query()->where('call_id', $current->getKey())->where('to_user_id', $me)->where('id', '>', $after)->orderBy('id')->limit(100)->get()
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

    /**
     * The ringing and active calls this user is still on, newest first: among the
     * people of the current context's scope (its guard), so two guards whose ids
     * overlap never share a call. A participant made in code is found in any.
     */
    public function openCallsOf(string $userId, ?string $scope = null): Collection
    {
        return Call::query()
            ->with('participants')
            ->where('status', '!=', Call::ENDED)
            ->where($this->involving($userId))
            ->when(! Participant::isGuestId($userId), fn (Builder $query) => $query->where('scope', $scope ?? $this->scope()))
            ->latest('id')
            ->get();
    }

    /** The scope of the people the current context names, never longer than the column. */
    public function scope(): string
    {
        return substr($this->context()->scope(), 0, 64);
    }

    /** Whether this person may see the call here: a user only within their own scope. */
    public function inScope(Call $call, string $userId): bool
    {
        return Participant::isGuestId($userId) || $call->scope === $this->scope();
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
        // Users are looked up afresh only from their own scope (another guard's table would name a
        // stranger); a guest, or anyone outside it, sees the names stored when people joined.
        $lookUp = ! Participant::isGuestId($me) && $call->scope === $this->scope();
        $people = $this->whoAre($lookUp ? $call->peersOf($me)->reject(fn (CallParticipant $peer) => Participant::isGuestId($peer->user_id))->pluck('user_id')->all() : []);

        foreach ($call->peersOf($me) as $peer) {
            if (! isset($people[$peer->user_id])) {
                $people[$peer->user_id] = ['id' => $peer->user_id, 'name' => $peer->name ?? __('calls::messages.someone'), 'avatar' => $peer->avatar];
            }
        }

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
            'kind' => $call->kind,
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
     * @param  array<int, Authenticatable|Participant|string|null>  $callees
     * @return array<int, Authenticatable|Participant>
     *
     * @throws CallNotAllowed
     */
    protected function resolveCallees(Authenticatable $caller, array $callees): array
    {
        $me = (string) $caller->getAuthIdentifier();
        $people = [];

        foreach ($callees as $callee) {
            if ($callee instanceof Participant) {
                $people[$callee->id] = $callee;

                continue;
            }

            // A string with a colon names a participant made in code: never looked up, so never rung from a route.
            $user = is_string($callee) || is_int($callee)
                ? (Participant::isGuestId((string) $callee) ? null : $this->findUser((string) $callee))
                : $callee;

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

    /** @throws SignalTooLarge */
    protected function assertSignalFits(array $payload): void
    {
        $kb = (int) config('packstub-calls.max_signal_kb', 64);

        if ($kb > 0 && strlen((string) json_encode($payload)) > $kb * 1024) {
            throw new SignalTooLarge(__('calls::messages.too_large', ['kb' => $kb]));
        }
    }

    /**
     * Signals are deleted once read (and all of them when the call ends), so only the unread
     * ones count: someone who keeps sending to people who never read cannot fill the table.
     *
     * @throws TooManySignals
     */
    protected function assertRoomForSignals(Call $call, string $fromUserId): void
    {
        $max = (int) config('packstub-calls.max_pending_signals', 500);

        if ($max > 0 && CallSignal::query()->where('call_id', $call->getKey())->where('from_user_id', $fromUserId)->count() >= $max) {
            throw new TooManySignals(__('calls::messages.too_many'));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function transaction(callable $callback): mixed
    {
        // A deadlock (a hang-up deleting the signals while a signal is written) is tried again:
        // every callback re-reads the call under its lock first, so a second run decides afresh.
        return DB::connection((new Call)->getConnectionName())->transaction($callback, 3);
    }

    /**
     * Take the call's row lock (inside a transaction) and re-read its status and
     * participants, so what follows decides on what is stored now.
     */
    protected function lock(Call $call): void
    {
        $fresh = Call::query()->whereKey($call->getKey())->lockForUpdate()->firstOrFail();

        $call->setRawAttributes($fresh->getAttributes(), true);
        $call->setRelation('participants', $call->participants()->get());
    }

    /**
     * The name and avatar stored with a participant: what a guest sees of them.
     *
     * @return array{name: ?string, avatar: ?string}
     */
    protected function storedName(Authenticatable|Participant $person): array
    {
        $who = $person instanceof Participant ? $person->toArray() : $this->who($person);

        return ['name' => mb_substr((string) $who['name'], 0, 255), 'avatar' => isset($who['avatar']) ? mb_substr((string) $who['avatar'], 0, 2048) : null];
    }

    protected static function idOf(Authenticatable|Participant $person): string
    {
        return $person instanceof Participant ? $person->id : (string) $person->getAuthIdentifier();
    }

    protected function involving(string $userId): \Closure
    {
        return fn (Builder $query) => $query->whereHas('participants', fn (Builder $participants) => $participants
            ->where('user_id', $userId)
            ->whereIn('status', CallParticipant::LIVE));
    }
}
