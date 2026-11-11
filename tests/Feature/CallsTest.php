<?php

use Illuminate\Support\Facades\Event;
use Packstub\Calls\AppContext;
use Packstub\Calls\Events\CallAnswered;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\CallStarted;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallSignal;
use Packstub\Calls\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

function ring(User $caller, User $callee): Call
{
    test()->actingAs($caller)->postJson(callsRoute('store'), ['callee' => $callee->getKey()])->assertCreated();

    return Call::query()->latest('id')->firstOrFail();
}

it('rings a user and tells the caller who they are calling', function (): void {
    Event::fake([CallStarted::class]);

    $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])
        ->assertCreated()
        ->assertJsonPath('status', 'ringing')
        ->assertJsonPath('role', 'caller')
        ->assertJsonPath('peer.name', 'Ana')
        ->assertJsonPath('peer.id', (string) $this->ana->getKey());

    Event::assertDispatched(CallStarted::class);
});

it('refuses a call to yourself, to nobody and to someone the context says no to', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertStatus(422);
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => '999'])->assertStatus(422);
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => ['not' => 'scalar']])->assertStatus(422);

    app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => $callee->name !== 'Ana');

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This person cannot be called.');

    app(AppContext::class)->canCallUsing(null);
});

it('shows the callee the incoming call and the caller the call they are in', function (): void {
    $call = ring($this->ion, $this->ana);

    $this->actingAs($this->ana)->getJson(callsRoute('poll'))
        ->assertOk()
        ->assertJsonPath('incoming.id', $call->id)
        ->assertJsonPath('incoming.role', 'callee')
        ->assertJsonPath('incoming.peer.name', 'Ion')
        ->assertJsonPath('call', null);

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))
        ->assertJsonPath('call.status', 'ringing')
        ->assertJsonPath('incoming', null);
});

it('delivers signals in order, only to the other side and only after the callee joins', function (): void {
    $call = ring($this->ion, $this->ana);

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => 'v=0 offer']])->assertCreated();
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['candidate' => 'c1']])->assertCreated();

    // Ringing: the callee is told about the call, not yet handed its signals.
    $this->actingAs($this->ana)->getJson(callsRoute('poll'))->assertJsonCount(0, 'signals')->assertJsonPath('cursor', 0);

    // The caller never sees their own signals.
    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->assertJsonCount(0, 'signals');

    Event::fake([CallAnswered::class]);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]))->assertOk()->assertJsonPath('status', 'active');
    Event::assertDispatched(CallAnswered::class);

    $response = $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))
        ->assertJsonPath('call.status', 'active')
        ->assertJsonPath('signals.0.type', 'offer')
        ->assertJsonPath('signals.0.payload.sdp', 'v=0 offer')
        ->assertJsonPath('signals.1.type', 'ice');

    $cursor = $response->json('cursor');
    expect($cursor)->toBe(CallSignal::query()->max('id'));

    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1, 'after' => $cursor]))->assertJsonCount(0, 'signals')->assertJsonPath('cursor', $cursor);

    $this->actingAs($this->ana)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'answer', 'payload' => ['sdp' => 'v=0 answer']])->assertCreated();
    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->assertJsonPath('signals.0.type', 'answer');
});

it('relays a state signal (mute, camera, screen share) like any other', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-tab'])->assertOk();

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'state', 'payload' => ['muted' => true, 'cameraOff' => false, 'sharing' => false, 'session' => 's1']])->assertCreated();

    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ana-tab', 'hold' => 1]))
        ->assertJsonPath('signals.0.type', 'state')
        ->assertJsonPath('signals.0.payload.muted', true)
        ->assertJsonPath('signals.0.payload.session', 's1');
});

it('rejects an unknown signal type and a signal from a bystander', function (): void {
    $call = ring($this->ion, $this->ana);
    $mihai = user('Mihai');

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'bogus', 'payload' => []])->assertStatus(422);
    $this->actingAs($mihai)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]])->assertForbidden();
    $this->actingAs($mihai)->postJson(callsRoute('answer', ['call' => $call]))->assertForbidden();
    $this->actingAs($mihai)->postJson(callsRoute('hangup', ['call' => $call]))->assertForbidden();
    $this->actingAs($mihai)->postJson(callsRoute('decline', ['call' => $call]))->assertForbidden();
    $this->actingAs($mihai)->post(callsRoute('leave', ['call' => $call]), ['tab' => 't'])->assertForbidden();
    $this->actingAs($mihai)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->assertJsonPath('call', null);
});

it('lets the callee decline and the caller learn about it', function (): void {
    Event::fake([CallEnded::class]);
    $call = ring($this->ion, $this->ana);

    $this->actingAs($this->ana)->postJson(callsRoute('decline', ['call' => $call]))->assertOk()->assertJsonPath('end_reason', 'declined');

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))
        ->assertJsonPath('call.status', 'ended')
        ->assertJsonPath('call.end_reason', 'declined');

    Event::assertDispatched(CallEnded::class, fn (CallEnded $event): bool => $event->call->end_reason === 'declined');

    // Answering a call that was declined is too late.
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]))->assertStatus(409)->assertJsonPath('message', 'This call is no longer ringing.');
});

it('marks a ringing call cancelled when the caller hangs up and hung up once it ran', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ion)->postJson(callsRoute('hangup', ['call' => $call]))->assertJsonPath('end_reason', 'cancelled');

    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]));
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]]);
    $this->travel(90)->seconds();

    $this->actingAs($this->ana)->postJson(callsRoute('hangup', ['call' => $call]))->assertJsonPath('end_reason', 'hung_up');

    expect($call->fresh()->duration())->toBe(90)
        ->and(CallSignal::query()->count())->toBe(0);

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]])->assertStatus(410);
});

it('marks a call nobody answered as missed', function (): void {
    $call = ring($this->ion, $this->ana);

    $this->travel(config('packstub-calls.ring_timeout') + 1)->seconds();

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))
        ->assertJsonPath('call.status', 'ended')
        ->assertJsonPath('call.end_reason', 'missed');

    $this->actingAs($this->ana)->getJson(callsRoute('poll'))->assertJsonPath('incoming', null);
});

it('tells a caller the other person is busy and ends the caller\'s own open call first', function (): void {
    $mihai = user('Mihai');
    $first = ring($this->ion, $this->ana);

    $this->actingAs($mihai)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This person is on another call.');

    // Ion rings Mihai instead: the call to Ana is cancelled.
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $mihai->getKey()])->assertCreated();
    expect($first->fresh()->end_reason)->toBe('cancelled');
});

it('hands a reloaded tab the call it is still in', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]));

    $this->actingAs($this->ana)->getJson(callsRoute('poll'))->assertJsonPath('call.id', $call->id)->assertJsonPath('call.status', 'active');
    $this->actingAs($this->ion)->getJson(callsRoute('poll'))->assertJsonPath('call.id', $call->id);
});

it('needs a signed-in user', function (): void {
    $this->getJson(callsRoute('poll'))->assertUnauthorized();
    $this->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertUnauthorized();
});

it('keeps a session description byte for byte, trailing CRLF included', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]));

    $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\na=ssrc:1 msid:a b\r\n";
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => $sdp]])->assertCreated();

    expect($this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->json('signals.0.payload.sdp'))->toBe($sdp);
});

it('keeps an empty ICE candidate empty: Firefox ends its candidates with one', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]));

    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['candidate' => '', 'sdpMid' => '0', 'sdpMLineIndex' => 0]])->assertCreated();

    expect($this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->json('signals.0.payload'))
        ->toBe(['candidate' => '', 'sdpMid' => '0', 'sdpMLineIndex' => 0]);
});

it('names the other side "Someone" when their account is gone', function (): void {
    $call = ring($this->ion, $this->ana);
    $this->ana->delete();

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))
        ->assertJsonPath('call.peer.name', 'Someone')
        ->assertJsonPath('call.peer.id', (string) $this->ana->getKey());
});
