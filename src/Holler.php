<?php

namespace Packstub\Holler;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Traits\Macroable;
use Packstub\Holler\Contracts\CallContext;
use Packstub\Holler\Events\CallAnswered;
use Packstub\Holler\Events\CallEnded;
use Packstub\Holler\Events\CallStarted;
use Packstub\Holler\Events\ParticipantHeld;
use Packstub\Holler\Events\ParticipantJoined;
use Packstub\Holler\Events\ParticipantLeft;
use Packstub\Holler\Events\ParticipantResumed;
use Packstub\Holler\Exceptions\CalleeBusy;
use Packstub\Holler\Exceptions\CallException;
use Packstub\Holler\Exceptions\CallFull;
use Packstub\Holler\Exceptions\CallHasEnded;
use Packstub\Holler\Exceptions\CallNotAllowed;
use Packstub\Holler\Exceptions\CallNotRinging;
use Packstub\Holler\Exceptions\SignalTooLarge;
use Packstub\Holler\Exceptions\TooManySignals;
use Packstub\Holler\Http\AssetController;
use Packstub\Holler\Http\CallsController;
use Packstub\Holler\Http\PopOutController;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;
use Packstub\Holler\Models\CallSignal;

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
 * others; the call ends once fewer than two people are left on it. Someone who
 * puts the call on hold stays on it without a window or media until they resume.
 */
class Holler
{
    use Macroable;

    /** How many matches a people search looks through for the few it shows. */
    public const SEARCH_CANDIDATES = 200;

    /**
     * Register the twelve JSON routes and the widget's pop-out page under `{prefix}/`, named `{as}poll`, `{as}store`…
     *
     * @param  array<int, string> | string | null  $middleware  null: `auth`, on the `guard` from the config when one is set
     * @param  bool  $window  also the widget's pop-out page, `GET {prefix}/window`
     */
    public function routes(string $prefix = 'calls', array|string|null $middleware = null, string $as = 'holler.', bool $window = true): void
    {
        $guard = config('holler.guard');
        $middleware ??= filled($guard) ? 'auth:'.$guard : 'auth';

        Route::prefix($prefix)->name($as)->middleware($middleware)->group(function () use ($window): void {
            Route::get('poll', [CallsController::class, 'poll'])->name('poll');
            // Asked when a call starts, so short-lived TURN credentials are fresh for each call.
            Route::get('ice-servers', [CallsController::class, 'ice'])->name('ice');
            // Ringing and searching are paced per person (`rate_limits` in the config); polls and signals are not.
            Route::post('/', [CallsController::class, 'store'])->name('store')->middleware('throttle:holler-calls');
            Route::post('{call}/answer', [CallsController::class, 'answer'])->name('answer')->whereNumber('call');
            Route::post('{call}/decline', [CallsController::class, 'decline'])->name('decline')->whereNumber('call');
            Route::post('{call}/hangup', [CallsController::class, 'hangup'])->name('hangup')->whereNumber('call');
            Route::post('{call}/invite', [CallsController::class, 'invite'])->name('invite')->whereNumber('call')->middleware('throttle:holler-calls');
            Route::post('{call}/hold', [CallsController::class, 'hold'])->name('hold')->whereNumber('call');
            Route::post('{call}/resume', [CallsController::class, 'resume'])->name('resume')->whereNumber('call');
            // Who can be added to a call: the search behind the widget's "Add someone".
            Route::get('people', [CallsController::class, 'people'])->name('people')->middleware('throttle:holler-people');
            Route::post('{call}/signals', [CallsController::class, 'signal'])->name('signal')->whereNumber('call');
            Route::post('{call}/leave', [CallsController::class, 'leave'])->name('leave')->whereNumber('call');
            // The pop-out page of `<x-holler::widget />`; a package with a window of its own passes `window: false`.
            if ($window) {
                Route::get('window', PopOutController::class)->name('window');
            }
        });
    }

    /** The URL of a file the package serves to the browser: `index.js` (the engine), `widget.js`, `widget.css`. */
    public function asset(string $file): string
    {
        return route('holler.asset', ['file' => $file]).'?v='.AssetController::version($file);
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
            'iceServers' => $this->iceServers(),
            'iceTransportPolicy' => config('holler.ice_transport_policy') === 'relay' ? 'relay' : 'all',
        ];
    }

    /**
     * The context's ICE servers in the shape a browser takes: several URLs in one string
     * (`turn:host:3478, turns:host:443?transport=tcp`) become a list, a server without a URL
     * is left out, and a server with a `secret` gets a username and a credential that
     * expire (see `turnCredentials()`); the secret itself never leaves the server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array
    {
        $servers = [];

        foreach ($this->context()->iceServers() as $server) {
            $urls = collect(Arr::wrap($server['urls'] ?? $server['url'] ?? []))
                ->flatMap(fn ($url) => explode(',', (string) $url))
                ->map(fn (string $url) => trim($url))
                ->filter()
                ->values()
                ->all();

            if ($urls === []) {
                continue;
            }

            $secret = $server['secret'] ?? null;
            $ttl = $server['ttl'] ?? null;
            unset($server['url'], $server['secret'], $server['ttl']);

            $server['urls'] = count($urls) === 1 ? $urls[0] : $urls;

            if (filled($secret)) {
                $server = [...$server, ...$this->turnCredentials((string) $secret, (int) ($ttl ?: 3600))];
            }

            $servers[] = array_filter($server, fn ($value) => $value !== null && $value !== '');
        }

        return $servers;
    }

    /**
     * A TURN username and credential that stop working after `$ttl` seconds, made from the
     * secret the TURN server shares with the app (coturn's `use-auth-secret` and
     * `static-auth-secret`, the "TURN REST API" scheme hosted services follow too): the
     * username is the expiry time and who asked, the credential its HMAC-SHA1 in base64.
     *
     * @return array{username: string, credential: string}
     */
    public function turnCredentials(string $secret, int $ttl = 3600, ?string $userId = null): array
    {
        $userId ??= $this->currentUserId();
        $username = (now()->getTimestamp() + max(60, $ttl)).(filled($userId) ? ':'.str_replace(':', '-', $userId) : '');

        return [
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
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
     * every check passed: a refused call leaves the current one alone. A call
     * the caller put on hold stays as it is.
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
            throw new CallNotAllowed(__('holler::messages.cannot_call'));
        }

        $people = $this->resolveCallees($caller, $list);

        // Everyone on a group call sees and hears everyone else: each pair of callees must be allowed too.
        $users = array_values(array_filter($people, fn ($person) => $person instanceof Authenticatable));
        foreach ($users as $i => $user) {
            $this->assertMayMeet($user, array_slice($users, 0, $i));
        }

        if (count($people) + 1 > $this->maxParticipants()) {
            throw new CallFull(__('holler::messages.full', ['max' => $this->maxParticipants()]));
        }

        // The calls I leave for this one; a call I put on hold waits for me.
        $mine = $this->openCallsOf($me, $scope)->reject(fn (Call $call) => $call->participant($me)?->isOnHold())->values();

        foreach ($people as $person) {
            $id = static::idOf($person);
            $theirs = $this->openCallsOf($id, $scope);
            // Calls nobody came back to are let go first: someone who closed every window is not busy.
            $this->expireCalls($theirs);

            // A call with me on it does not count when my leaving ends it, or when they only ring
            // on it (two people ringing each other, say); a call they are in with others does.
            // Nor does a call they put on hold themselves: they stepped out of that one.
            $busy = $theirs->contains(fn (Call $call) => ! $call->isEnded() && ! $call->participant($id)?->isOnHold() && (
                ! $mine->contains(fn (Call $own) => $own->is($call))
                || ($call->participant($id)?->isJoined() && $call->live()->count() > 2)
            ));

            if ($busy) {
                throw new CalleeBusy(__('holler::messages.busy'));
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
            throw new CallNotAllowed(__('holler::messages.join_first'));
        }

        [$person] = $inviter ? $this->resolveCallees($inviter, [$callee]) : [null];

        // The newcomer meets everyone already on the call, not only the person who brings them in.
        if ($person instanceof Authenticatable) {
            $this->assertMayMeet($person, $call->live()
                ->reject(fn (CallParticipant $participant) => $participant->user_id === $byUserId || Participant::isGuestId($participant->user_id))
                ->map(fn (CallParticipant $participant) => $this->findUser($participant->user_id))
                ->filter());
        }

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
                throw new CallHasEnded(__('holler::messages.ended'));
            }

            if (! $call->participant($byUserId)?->isJoined()) {
                throw new CallNotAllowed(__('holler::messages.join_first'));
            }

            // A guest agreed to the people on their call, not to whoever those bring in.
            if ($call->participants->contains(fn (CallParticipant $participant) => Participant::isGuestId($participant->user_id))) {
                throw new CallNotAllowed(__('holler::messages.cannot_call'));
            }

            $personId = static::idOf($person);
            $existing = $call->participant($personId);

            if ($existing?->isLive()) {
                throw new CallNotAllowed(__('holler::messages.cannot_call'));
            }

            if ($call->live()->count() + 1 > $this->maxParticipants()) {
                throw new CallFull(__('holler::messages.full', ['max' => $this->maxParticipants()]));
            }

            if ($this->openCallsOf($personId, $call->scope)->contains(fn (Call $other) => ! $other->participant($personId)?->isOnHold())) {
                throw new CalleeBusy(__('holler::messages.busy'));
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
                throw new CallNotRinging(__('holler::messages.not_ringing'));
            }

            if ($this->openCallsOf($userId, $call->scope)->contains(fn (Call $other) => ! $other->is($call) && $other->participant($userId)?->isJoined())) {
                throw new CalleeBusy(__('holler::messages.busy'));
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

    /**
     * Step out of a call without leaving it: my side goes on hold, my window lets go of
     * it and the others stay on the call, where they see me as `on_hold`. One call on
     * hold per person; `resume()` brings me back.
     *
     * @throws CallHasEnded
     * @throws CallNotAllowed not in the call, the call still rings, or another call is on hold already
     */
    public function hold(Call $call, string $userId): Call
    {
        // Looked at before the lock: never two calls locked at once.
        if ($this->openCallsOf($userId, $call->scope)->contains(fn (Call $other) => ! $other->is($call) && $other->participant($userId)?->isOnHold())) {
            throw new CallNotAllowed(__('holler::messages.already_holding'));
        }

        $this->putOnHold($call, $userId);

        return $call;
    }

    /**
     * Come back to a call I put on hold; my window then connects afresh, the way a page
     * does after a reload. A call I am in meanwhile goes on hold in its place (a swap),
     * and one I am still ringing someone on is given up.
     *
     * @throws CallHasEnded
     * @throws CallNotAllowed the call is not on hold for this person
     */
    public function resume(Call $call, string $userId, ?string $tab = null): Call
    {
        if (! $call->isEnded() && $call->participant($userId)?->isOnHold()) {
            foreach ($this->openCallsOf($userId, $call->scope) as $other) {
                $mine = $other->participant($userId);

                if ($other->is($call) || ! $mine?->isJoined()) {
                    continue;
                }

                try {
                    $other->isActive() ? $this->putOnHold($other, $userId) : $this->withdraw($other, $mine);
                } catch (CallException) {
                    // It ended meanwhile: nothing left to put on hold.
                }
            }
        }

        $me = $this->transaction(function () use ($call, $userId, $tab): CallParticipant {
            $this->lock($call);
            $me = $call->participant($userId);

            if ($call->isEnded()) {
                throw new CallHasEnded(__('holler::messages.ended'));
            }

            if (! $me?->isOnHold()) {
                throw new CallNotAllowed(__('holler::messages.not_on_hold'));
            }

            // Seen now, so the reconnect timeout counts from the resume while the window connects.
            $me->forceFill(['status' => CallParticipant::JOINED, 'tab' => $tab, 'seen_at' => now()])->save();

            return $me;
        });

        ParticipantResumed::dispatch($call, $me);

        return $call;
    }

    /** Under a lock on the call: someone in a running call steps out of it; twice is once. */
    protected function putOnHold(Call $call, string $userId): void
    {
        $held = $this->transaction(function () use ($call, $userId): ?CallParticipant {
            $this->lock($call);
            $me = $call->participant($userId);

            if ($call->isEnded()) {
                throw new CallHasEnded(__('holler::messages.ended'));
            }

            if ($me?->isOnHold()) {
                return null;
            }

            if (! $call->isActive() || ! $me?->isJoined()) {
                throw new CallNotAllowed(__('holler::messages.cannot_hold'));
            }

            // `seen_at` goes on as the heartbeat of someone away: their polls keep the hold (see `poll()`).
            $me->forceFill(['status' => CallParticipant::ON_HOLD, 'tab' => null, 'seen_at' => now()])->save();
            // What was on its way to the connection I just closed; I start over when I resume.
            CallSignal::query()->where('call_id', $call->getKey())->where('to_user_id', $userId)->delete();

            return $me;
        });

        if ($held) {
            ParticipantHeld::dispatch($call, $held);
        }
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
                'status' => $status ?? ($participant->isRinging() ? CallParticipant::DECLINED : CallParticipant::LEFT),
                'tab' => null,
                'left_at' => now(),
            ])->save();

            $live = $call->live();
            // Someone away with the call on hold is still in it: the call waits for them.
            $joined = $live->filter(fn (CallParticipant $other) => $other->isJoined() || $other->isOnHold());

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
     * Only someone in the call sends: not someone still ringing, on hold or gone;
     * and nothing is kept for someone on hold, who connects afresh on resuming.
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
            throw new CallHasEnded(__('holler::messages.ended'));
        }

        if (! $call->participant($fromUserId)?->isJoined()) {
            throw new CallNotAllowed(__('holler::messages.not_on_call'));
        }

        $this->assertSignalFits($payload);
        $this->assertRoomForSignals($call, $fromUserId);

        $recipients = $call->live()->filter(fn (CallParticipant $participant) => $participant->user_id !== $fromUserId && ! $participant->isOnHold());

        if ($to !== null) {
            if (! $call->involves($to)) {
                throw new CallNotAllowed(__('holler::messages.not_on_call'));
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
     * is dropped (and a call that leaves fewer than two people ends as dropped),
     * and so is someone who put the call on hold and has not polled for `hold_timeout`.
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
        $ringDeadline = now()->subSeconds((int) config('holler.ring_timeout', 45));
        $dropDeadline = now()->subSeconds((int) config('holler.reconnect_timeout', 15));

        // A side that never named a window has not polled since it joined.
        $missed = fn (CallParticipant $participant): bool => $participant->isRinging() && $participant->invited_at?->lt($ringDeadline) === true;
        $dropped = fn (CallParticipant $participant, Call $call): bool => $participant->isJoined() && $call->isActive()
            && ($participant->seen_at ?? $participant->joined_at)?->lt($dropDeadline) === true;
        // Away with the call on hold, and no window of theirs has polled since: they closed everything.
        $holdTimeout = (int) config('holler.hold_timeout', 120);
        $holdDeadline = now()->subSeconds($holdTimeout);
        $abandoned = fn (CallParticipant $participant): bool => $holdTimeout > 0 && $participant->isOnHold()
            && $participant->seen_at?->lt($holdDeadline) === true;

        foreach ($calls as $call) {
            foreach ($call->live() as $participant) {
                // Decided on this copy, checked again on the row under the lock.
                if ($missed($participant)) {
                    $this->withdrawIf($call, $participant, CallParticipant::MISSED, $missed);
                } elseif ($dropped($participant, $call)) {
                    $this->withdrawIf($call, $participant, CallParticipant::DROPPED, $dropped);
                } elseif ($abandoned($participant)) {
                    $this->withdrawIf($call, $participant, CallParticipant::DROPPED, $abandoned);
                }
            }
        }
    }

    /**
     * What a window learns on each poll: the call it is on (claimed by it from then
     * on when it asks to `claim` it, which is also its heartbeat) or any open call of
     * mine for a page that just loaded, a call ringing me, the call I put on hold
     * (`on_hold`; asking is the heartbeat that keeps it) and my new signals; only
     * calls of `$kind` when given.
     *
     * @return array{call: ?array<string, mixed>, incoming: ?array<string, mixed>, on_hold: ?array<string, mixed>, signals: array<int, array<string, mixed>>, cursor: int}
     */
    public function poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $claim = false, ?string $kind = null): array
    {
        $open = $this->openCallsOf($me);

        // Before anything expires: a poll from any window of mine keeps the call I put on hold.
        foreach ($open as $call) {
            $mine = $call->participant($me);

            if ($mine?->isOnHold() && $mine->seen_at?->gt(now()->subSeconds(Call::CLAIM_SECONDS)) !== true) {
                $mine->forceFill(['seen_at' => now()])->save();
            }
        }

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
        // A call I put on hold is not this window's call either: it comes back as `on_hold`.
        if ($current && (! $current->involves($me) || ! $this->inScope($current, $me) || ($kind !== null && $current->kind !== $kind) || (! $current->isEnded() && (! $current->participant($me)->isLive() || $current->participant($me)->isOnHold())))) {
            $current = null;
        }

        $claimed = false;

        if ($current && $currentId && $tab && $claim && ! $current->isEnded() && $current->participant($me)->isLive()) {
            $claimed = $current->participant($me)->claim($tab, $reclaimed);

            // The window that has the side read everything up to its cursor: those signals are done with.
            if ($claimed && $after > 0) {
                CallSignal::query()->where('call_id', $current->getKey())->where('to_user_id', $me)->where('id', '<=', $after)->delete();
            }

            if ($reclaimed) {
                // A fresh page starts after the signals meant for the old connection (its own
                // session check covers the rest); they stay in case the old window is still alive.
                $after = max($after, (int) CallSignal::query()->where('call_id', $current->getKey())->max('id'));
            }
        }

        $incoming = $open->first(fn (Call $call) => ! $call->isEnded() && $call->participant($me)?->isRinging() && ! $current?->is($call));

        // Signals only flow to the window that claimed the side: a callee gets the offers once they answer.
        $signals = $claimed
            ? CallSignal::query()->where('call_id', $current->getKey())->where('to_user_id', $me)->where('id', '>', $after)->orderBy('id')->limit(100)->get()
            : collect();

        $onHold = $open->first(fn (Call $call) => ! $call->isEnded() && $call->participant($me)?->isOnHold());

        return [
            'call' => $current ? $this->present($current, $me, $tab) : null,
            'incoming' => $incoming ? $this->present($incoming, $me, $tab) : null,
            'on_hold' => $onHold ? $this->present($onHold, $me, $tab) : null,
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
     * and heartbeat), my seat, whether I joined or put it on hold, plus `peer`, `peer_claimed` and
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
                $people[$peer->user_id] = ['id' => $peer->user_id, 'name' => $peer->name ?? __('holler::messages.someone'), 'avatar' => $peer->avatar];
            }
        }

        $peers = $call->peersOf($me)->map(fn (CallParticipant $peer) => [
            ...$people[$peer->user_id],
            'seat' => $peer->seat,
            'status' => $peer->status,
            'claimed' => $peer->isClaimed(),
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
            // I stepped out of it (`hold()`).
            'on_hold' => $mine?->isOnHold() ?? false,
            'peers' => $peers->all(),
            'peer' => $first ? ['id' => $first['id'], 'name' => $first['name'], 'avatar' => $first['avatar']] : null,
            'answered_at' => $call->answered_at?->toIso8601String(),
            // Another window of mine has claimed my side (a pop-out runs the call).
            'claimed_elsewhere' => $mine !== null && $mine->isClaimed() && $mine->tab !== $tab,
            'peer_claimed' => $first['claimed'] ?? false,
            'peer_seen_ago' => $first['seen_ago'] ?? null,
        ];
    }

    /**
     * Who this person can ring, or add to `$call`: what the context's `searchUsers()` finds
     * for the words typed, without the person asking, anyone `canCall()` refuses, and, for
     * a call, anyone already on it or kept apart from someone on it. Nobody at all when
     * `people_search` is off.
     *
     * @return array<int, array{id: string, name: string, avatar: ?string}>
     */
    public function people(Authenticatable $me, string $query = '', ?Call $call = null, int $limit = 8): array
    {
        if (! config('holler.people_search', true)) {
            return [];
        }

        $context = $this->context();
        $myId = (string) $me->getAuthIdentifier();
        $onCall = $call ? $call->live()->pluck('user_id')->all() : [];
        $others = $this->findUsers(array_values(array_filter($onCall, fn (string $id) => $id !== $myId && ! Participant::isGuestId($id))));
        $people = [];

        // Far more than shown, since some are filtered out below: where the context keeps most
        // people apart (tenants sharing one users table), the first few matches may all be strangers.
        foreach ($context->searchUsers($me, mb_substr(trim($query), 0, 64), max($limit, static::SEARCH_CANDIDATES)) as $user) {
            $id = (string) $user->getAuthIdentifier();

            if ($id === $myId || isset($people[$id]) || in_array($id, $onCall, true) || ! $context->canCall($me, $user)) {
                continue;
            }

            if (collect($others)->contains(fn (Authenticatable $other) => ! $context->canCall($other, $user))) {
                continue;
            }

            $people[$id] = $this->who($user);

            if (count($people) >= $limit) {
                break;
            }
        }

        return array_values($people);
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
        $found = [];

        foreach ($this->findUsers($userIds) as $user) {
            $found[(string) $user->getAuthIdentifier()] = $this->who($user);
        }

        $people = [];

        foreach ($userIds as $userId) {
            $people[$userId] = $found[$userId] ?? ['id' => $userId, 'name' => __('holler::messages.someone'), 'avatar' => null];
        }

        return $people;
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<int, Authenticatable>
     */
    protected function findUsers(array $userIds): array
    {
        $context = $this->context();

        return method_exists($context, 'findUsers')
            ? $context->findUsers($userIds)
            : array_values(array_filter(array_map(fn (string $id) => $context->findUser($id), $userIds)));
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
                throw new CallNotAllowed(__('holler::messages.cannot_call'));
            }

            $people[(string) $user->getAuthIdentifier()] = $user;
        }

        if ($people === []) {
            throw new CallNotAllowed(__('holler::messages.cannot_call'));
        }

        return array_values($people);
    }

    /**
     * `canCall()` between someone joining a call and each person already on it (either way round
     * is asked once, the newcomer as the callee), so a group never puts together two people the
     * context keeps apart: two tenants, say, that only the caller shares.
     *
     * @param  iterable<Authenticatable>  $others
     *
     * @throws CallNotAllowed
     */
    protected function assertMayMeet(Authenticatable $newcomer, iterable $others): void
    {
        foreach ($others as $other) {
            if ((string) $other->getAuthIdentifier() !== (string) $newcomer->getAuthIdentifier() && ! $this->context()->canCall($other, $newcomer)) {
                throw new CallNotAllowed(__('holler::messages.cannot_call'));
            }
        }
    }

    /** @throws SignalTooLarge */
    protected function assertSignalFits(array $payload): void
    {
        $kb = (int) config('holler.max_signal_kb', 64);

        if ($kb > 0 && strlen((string) json_encode($payload)) > $kb * 1024) {
            throw new SignalTooLarge(__('holler::messages.too_large', ['kb' => $kb]));
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
        $max = (int) config('holler.max_pending_signals', 500);

        if ($max > 0 && CallSignal::query()->where('call_id', $call->getKey())->where('from_user_id', $fromUserId)->count() >= $max) {
            throw new TooManySignals(__('holler::messages.too_many'));
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
