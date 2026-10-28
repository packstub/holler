<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Calls\AppContext;
use Packstub\Calls\Events\CallAnswered;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\ParticipantJoined;
use Packstub\Calls\Events\ParticipantLeft;
use Packstub\Calls\Exceptions\CallFull;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallSignal;
use Packstub\Calls\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->mihai = user('Mihai');
    $this->dana = user('Dana');
});

/** Ion rings Ana and Mihai at once. */
function ringGroup(User $caller, array $callees, string $tab = 'ion-1'): Call
{
    test()->actingAs($caller)->postJson(callsRoute('store'), ['callees' => array_map(fn (User $user) => $user->getKey(), $callees), 'tab' => $tab])->assertCreated();

    return Call::query()->latest('id')->firstOrFail();
}

function answerAs(User $user, Call $call, string $tab)
{
    return test()->actingAs($user)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => $tab]);
}

/** Time passes while these people's windows keep polling (every 5 seconds here), as browsers do. */
function keepPolling(Call $call, array $users, int $seconds): void
{
    for ($elapsed = 0; $elapsed < $seconds; $elapsed += 5) {
        test()->travel(min(5, $seconds - $elapsed))->seconds();

        foreach ($users as $user) {
            pollHolding($user, $call);
        }
    }
}

function pollHolding(User $user, Call $call)
{
    return test()->actingAs($user)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => strtolower($user->name).'-1', 'hold' => 1]));
}

it('rings several people at once, each at the next seat, and shows everyone the roster', function (): void {
    $response = $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->mihai->getKey()], 'tab' => 'ion-1'])
        ->assertCreated()
        ->assertJsonPath('status', 'ringing')
        ->assertJsonPath('role', 'caller')
        ->assertJsonPath('seat', 0)
        ->assertJsonPath('joined', true)
        ->assertJsonPath('peer.name', 'Ana')
        ->assertJsonCount(2, 'peers')
        ->assertJsonPath('peers.0', ['id' => (string) $this->ana->getKey(), 'name' => 'Ana', 'avatar' => null, 'seat' => 1, 'status' => 'ringing', 'held' => false, 'seen_ago' => null])
        ->assertJsonPath('peers.1.name', 'Mihai')
        ->assertJsonPath('peers.1.seat', 2);

    $call = Call::query()->find($response->json('id'));
    expect($call->callee_id)->toBe((string) $this->ana->getKey())
        ->and($call->participants)->toHaveCount(3);

    // Each invitee is rung and sees the others, the caller joined and the other invitee ringing.
    $this->actingAs($this->mihai)->getJson(callsRoute('poll'))
        ->assertJsonPath('incoming.id', $call->id)
        ->assertJsonPath('incoming.role', 'callee')
        ->assertJsonPath('incoming.seat', 2)
        ->assertJsonPath('incoming.joined', false)
        ->assertJsonPath('incoming.peer.name', 'Ion')
        ->assertJsonPath('incoming.peers.0.status', 'joined')
        ->assertJsonPath('incoming.peers.1.name', 'Ana')
        ->assertJsonPath('incoming.peers.1.status', 'ringing');
});

it('routes a signal to one participant with `to`, to everyone else without, and tells who it is from', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1')->assertOk()->assertJsonPath('status', 'active');
    answerAs($this->mihai, $call, 'mihai-1')->assertOk();

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => 'to ana'], 'to' => (string) $this->ana->getKey()])->assertCreated();
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'state', 'payload' => ['muted' => true]])->assertCreated();
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1], 'to' => (string) $this->dana->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That person is not on this call.');

    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ana-1', 'hold' => 1]))
        ->assertJsonCount(2, 'signals')
        ->assertJsonPath('signals.0.type', 'offer')
        ->assertJsonPath('signals.0.from', (string) $this->ion->getKey())
        ->assertJsonPath('signals.1.type', 'state');

    $this->actingAs($this->mihai)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'mihai-1', 'hold' => 1]))
        ->assertJsonCount(1, 'signals')
        ->assertJsonPath('signals.0.type', 'state')
        ->assertJsonPath('signals.0.from', (string) $this->ion->getKey());

    expect(CallSignal::query()->count())->toBe(3);
});

it('goes on when one person declines and ends only once nobody is left', function (): void {
    Event::fake([CallEnded::class, ParticipantLeft::class]);
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);

    $this->actingAs($this->mihai)->postJson(callsRoute('decline', ['call' => $call]))->assertOk()->assertJsonPath('status', 'ringing');
    Event::assertDispatched(ParticipantLeft::class, fn (ParticipantLeft $event): bool => $event->participant->status === 'declined');
    Event::assertNotDispatched(CallEnded::class);

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ion-1', 'hold' => 1]))
        ->assertJsonPath('call.status', 'ringing')
        ->assertJsonPath('call.peers.1.status', 'declined')
        ->assertJsonPath('call.peer.name', 'Ana');

    // Mihai is off the call: no more incoming call, and no answering it.
    $this->actingAs($this->mihai)->getJson(callsRoute('poll'))->assertJsonPath('incoming', null);
    answerAs($this->mihai, $call, 'mihai-2')->assertStatus(409);

    $this->actingAs($this->ana)->postJson(callsRoute('decline', ['call' => $call]))->assertOk()->assertJsonPath('status', 'ended')->assertJsonPath('end_reason', 'declined');
    Event::assertDispatched(CallEnded::class);
});

it('keeps the call for the others when someone hangs up, and ends it as hung up when one person is left', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1');
    answerAs($this->mihai, $call, 'mihai-1');
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]]);

    $this->actingAs($this->ion)->postJson(callsRoute('hangup', ['call' => $call]))->assertOk()->assertJsonPath('status', 'active');

    // The caller is gone, the call runs on between Ana and Mihai; Ion's pending signals went with him.
    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ana-1', 'hold' => 1]))
        ->assertJsonPath('call.status', 'active')
        ->assertJsonPath('call.peers.0.status', 'left')
        ->assertJsonPath('call.peers.1.status', 'joined')
        ->assertJsonPath('call.peer.name', 'Mihai');
    $this->actingAs($this->ion)->getJson(callsRoute('poll'))->assertJsonPath('call', null)->assertJsonPath('incoming', null);
    // His other windows, still naming the call, are done with it too; once it ends, everyone learns the reason.
    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ion-chip']))->assertJsonPath('call', null);

    $this->actingAs($this->mihai)->postJson(callsRoute('hangup', ['call' => $call]))->assertJsonPath('status', 'ended')->assertJsonPath('end_reason', 'hung_up');
    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ion-chip']))->assertJsonPath('call.status', 'ended')->assertJsonPath('call.end_reason', 'hung_up');
});

it('adds someone to a running call, who rings and sees everyone, and re-rings someone who left', function (): void {
    Event::fake([ParticipantJoined::class, CallAnswered::class]);
    $call = ringGroup($this->ion, [$this->ana]);
    answerAs($this->ana, $call, 'ana-1');
    Event::assertDispatched(CallAnswered::class);
    Event::assertDispatchedTimes(ParticipantJoined::class, 1);

    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->mihai->getKey()])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('peers.1.name', 'Mihai')
        ->assertJsonPath('peers.1.seat', 2)
        ->assertJsonPath('peers.1.status', 'ringing');

    $this->actingAs($this->mihai)->getJson(callsRoute('poll'))
        ->assertJsonPath('incoming.id', $call->id)
        ->assertJsonPath('incoming.status', 'active')
        ->assertJsonPath('incoming.joined', false)
        ->assertJsonPath('incoming.seat', 2)
        ->assertJsonCount(2, 'incoming.peers');

    // Inviting someone already on the call, or yourself, is refused; so is inviting from outside.
    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->mihai->getKey()])->assertStatus(422);
    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->ana->getKey()])->assertStatus(422);
    $this->actingAs($this->mihai)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->dana->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Join the call before adding people to it.');
    $this->actingAs($this->dana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->mihai->getKey()])->assertForbidden();

    answerAs($this->mihai, $call, 'mihai-1')->assertOk()->assertJsonPath('status', 'active');
    Event::assertDispatchedTimes(ParticipantJoined::class, 2);
    Event::assertDispatchedTimes(CallAnswered::class, 1);

    // Mihai leaves and is asked back: a new seat, ringing again.
    $this->actingAs($this->mihai)->postJson(callsRoute('hangup', ['call' => $call]))->assertOk();
    $this->actingAs($this->ion)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $this->mihai->getKey()])
        ->assertOk()
        ->assertJsonPath('peers.1.status', 'ringing')
        ->assertJsonPath('peers.1.seat', 3);
    expect($call->participants()->count())->toBe(3);
});

it('looks the whole roster up in one query on each poll', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai, $this->dana]);

    DB::enableQueryLog();
    $response = pollHolding($this->ion, $call)->assertOk()->assertJsonCount(3, 'call.peers');
    $userQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "users"'));
    DB::disableQueryLog();

    expect($userQueries)->toHaveCount(1);
    expect(collect($response->json('call.peers'))->pluck('name')->all())->toBe(['Ana', 'Mihai', 'Dana']);

    // A context without `findUsers()` is looked up one by one and still names everyone.
    $people = Calls::whoAre([(string) $this->ana->getKey(), 'nobody']);
    expect($people[(string) $this->ana->getKey()]['name'])->toBe('Ana');
    expect($people['nobody']['name'])->toBe('Someone');
});

it('caps a call at max_participants, on start and on invite', function (): void {
    $vlad = user('Vlad');
    $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->mihai->getKey(), $this->dana->getKey(), $vlad->getKey()]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A call takes up to 4 people.');

    $call = ringGroup($this->ion, [$this->ana, $this->mihai, $this->dana]);
    answerAs($this->ana, $call, 'ana-1');

    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $vlad->getKey()])->assertStatus(422);

    // The context decides the limit, and never below two.
    app(AppContext::class)->maxParticipantsUsing(2);
    expect(Calls::maxParticipants())->toBe(2)
        ->and(fn () => Calls::start(user('Radu'), [$vlad, user('Vera')]))->toThrow(CallFull::class);
    app(AppContext::class)->maxParticipantsUsing(1);
    expect(Calls::maxParticipants())->toBe(2);
    app(AppContext::class)->maxParticipantsUsing(null);
});

it('refuses a group with someone busy or not allowed, and rings each person once', function (): void {
    $other = ringGroup($this->mihai, [$this->dana], 'mihai-1');

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->dana->getKey()]])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This person is on another call.');

    app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => $callee->name !== 'Ana');
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->mihai->getKey()]])->assertStatus(422);
    app(AppContext::class)->canCallUsing(null);

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->ana->getKey(), $this->ion->getKey()]])->assertStatus(422);
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => [$this->ana->getKey(), $this->ana->getKey()]])->assertCreated()->assertJsonCount(1, 'peers');
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => []])->assertStatus(422);
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callees' => 'ana'])->assertStatus(422);

    // Starting a new call leaves the one I am on rather than ending it for the others.
    answerAs($this->dana, $other, 'dana-1');
    $this->actingAs($this->mihai)->postJson(callsRoute('invite', ['call' => $other]), ['callee' => user('Vlad')->getKey()])->assertOk();
    $this->actingAs($this->mihai)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertStatus(409); // Ion is ringing Ana
    $this->actingAs($this->ion)->postJson(callsRoute('hangup', ['call' => Call::query()->latest('id')->first()]));
    $this->actingAs($this->mihai)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertCreated();
    expect($other->fresh()->status)->toBe('active')
        ->and(participantOf($other, $this->mihai)->status)->toBe('left');
});

it('marks an invitee who never answers as missed while the call goes on, and a quiet participant as dropped', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1');

    keepPolling($call, [$this->ion, $this->ana], config('packstub-calls.ring_timeout') + 1);
    pollHolding($this->ion, $call)
        ->assertJsonPath('call.status', 'active')
        ->assertJsonPath('call.peers.1.status', 'missed');
    $this->actingAs($this->mihai)->getJson(callsRoute('poll'))->assertJsonPath('incoming', null);

    // Ana's window goes quiet: she is dropped, and with Ion alone the call ends as dropped.
    $this->actingAs($this->ana)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'ana-1']);
    keepPolling($call, [$this->ion], config('packstub-calls.reconnect_timeout') + 1);
    pollHolding($this->ion, $call)
        ->assertJsonPath('call.status', 'ended')
        ->assertJsonPath('call.end_reason', 'dropped');
    expect(participantOf($call, $this->ana)->status)->toBe('dropped');
});

it('drops one quiet participant from a group of three and keeps the call for the other two', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1');
    answerAs($this->mihai, $call, 'mihai-1');

    $this->actingAs($this->mihai)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'mihai-1']);
    // Ion and Ana keep polling meanwhile, so they are not stale themselves.
    keepPolling($call, [$this->ion, $this->ana], config('packstub-calls.reconnect_timeout') + 1);

    pollHolding($this->ana, $call)
        ->assertJsonPath('call.status', 'active')
        ->assertJsonPath('call.peers.1.status', 'dropped');
    pollHolding($this->ion, $call)->assertJsonPath('call.status', 'active');
});

it('runs a group through the service without HTTP', function (): void {
    $call = Calls::start($this->ion, [$this->ana, (string) $this->mihai->getKey()], 'ion-1');

    expect($call->participants)->toHaveCount(3)
        ->and($call->live())->toHaveCount(3)
        ->and($call->peerOf((string) $this->ion->getKey()))->toBe((string) $this->ana->getKey());

    Calls::answer($call, (string) $this->mihai->getKey(), 'mihai-1');
    expect($call->isActive())->toBeTrue()
        ->and(Calls::signal($call, (string) $this->ion->getKey(), 'state', ['muted' => true]))->not->toBeNull()
        ->and(CallSignal::query()->count())->toBe(2)
        ->and(Calls::signal($call, (string) $this->ion->getKey(), 'offer', ['sdp' => 'x'], (string) $this->mihai->getKey())?->to_user_id)->toBe((string) $this->mihai->getKey());

    Calls::invite($call, (string) $this->mihai->getKey(), $this->dana);
    expect($call->participants)->toHaveCount(4)
        ->and($call->participant((string) $this->dana->getKey())->seat)->toBe(3);

    Calls::hangup($call, (string) $this->ion->getKey());
    expect($call->fresh()->isActive())->toBeTrue()
        ->and(Calls::openCallsOf((string) $this->ion->getKey()))->toHaveCount(0)
        ->and(Calls::openCallsOf((string) $this->dana->getKey()))->toHaveCount(1);
});
