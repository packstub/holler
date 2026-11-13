<?php

use Illuminate\Support\Facades\Event;
use Packstub\Holler\Events\CallEnded;
use Packstub\Holler\Events\ParticipantHeld;
use Packstub\Holler\Events\ParticipantResumed;
use Packstub\Holler\Exceptions\CallNotAllowed;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;
use Packstub\Holler\Models\CallSignal;
use Packstub\Holler\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->mihai = user('Mihai');
    $this->dana = user('Dana');
});

/** A running 1-on-1 call: the caller rang, the callee answered. */
function talk(User $caller, User $callee): Call
{
    $call = ringGroup($caller, [$callee], strtolower($caller->name).'-1');
    answerAs($callee, $call, strtolower($callee->name).'-1')->assertOk();

    return $call;
}

function holdAs(User $user, Call $call)
{
    return test()->actingAs($user)->postJson(callsRoute('hold', ['call' => $call]));
}

function resumeAs(User $user, Call $call, ?string $tab = null)
{
    return test()->actingAs($user)->postJson(callsRoute('resume', ['call' => $call]), ['tab' => $tab ?? strtolower($user->name).'-1']);
}

it('puts my side of a call on hold without ending it for the other person', function (): void {
    Event::fake([ParticipantHeld::class, CallEnded::class]);
    $call = talk($this->ion, $this->ana);
    Holler::signal($call, (string) $this->ana->getKey(), 'ice', ['candidate' => 'a']);

    holdAs($this->ion, $call)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('on_hold', true)
        ->assertJsonPath('joined', false);

    expect(participantOf($call, $this->ion))->status->toBe(CallParticipant::ON_HOLD)->tab->toBeNull()
        ->and($call->fresh()->status)->toBe(Call::ACTIVE)
        // What was on its way to the connection Ion closed is gone.
        ->and(CallSignal::query()->where('to_user_id', (string) $this->ion->getKey())->count())->toBe(0);

    Event::assertDispatched(ParticipantHeld::class);
    Event::assertNotDispatched(CallEnded::class);

    // Ana still has her call and sees Ion on hold; Ion's windows get it as `on_hold`, not as their call.
    pollHolding($this->ana, $call)->assertJsonPath('call.status', 'active')->assertJsonPath('call.peers.0.status', 'on_hold');
    pollHolding($this->ion, $call)->assertJsonPath('call', null)->assertJsonPath('on_hold.id', $call->id)->assertJsonPath('on_hold.peer.name', 'Ana');
    $this->actingAs($this->ion)->getJson(callsRoute('poll'))->assertJsonPath('call', null)->assertJsonPath('on_hold.id', $call->id);

    // Holding twice is holding once.
    holdAs($this->ion, $call)->assertOk();
});

it('only holds a running call I am in', function (): void {
    $ringing = ringGroup($this->ion, [$this->ana]);

    holdAs($this->ion, $ringing)->assertStatus(422)->assertJsonPath('reason', 'not_allowed');
    holdAs($this->ana, $ringing)->assertStatus(422);
    holdAs($this->mihai, $ringing)->assertForbidden();

    answerAs($this->ana, $ringing, 'ana-1')->assertOk();
    $this->actingAs($this->ana)->postJson(callsRoute('hangup', ['call' => $ringing]))->assertOk();

    holdAs($this->ion, $ringing)->assertStatus(410);
});

it('lets me ring someone else while a call is on hold, and keeps the held call', function (): void {
    $first = talk($this->ion, $this->ana);
    holdAs($this->ion, $first)->assertOk();

    $second = ringGroup($this->ion, [$this->mihai], 'ion-1');

    expect($first->fresh()->status)->toBe(Call::ACTIVE)
        ->and(participantOf($first, $this->ion)->status)->toBe(CallParticipant::ON_HOLD);

    answerAs($this->mihai, $second, 'mihai-1')->assertOk();

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $second->id, 'tab' => 'ion-1', 'claim' => 1]))
        ->assertJsonPath('call.id', $second->id)
        ->assertJsonPath('on_hold.id', $first->id);

    // One call on hold at a time.
    holdAs($this->ion, $second)->assertStatus(422)->assertJsonPath('message', 'You already have a call on hold.');
});

it('swaps the calls when I resume the one on hold', function (): void {
    Event::fake([ParticipantResumed::class]);
    $first = talk($this->ion, $this->ana);
    holdAs($this->ion, $first)->assertOk();
    $second = ringGroup($this->ion, [$this->mihai], 'ion-1');
    answerAs($this->mihai, $second, 'mihai-1')->assertOk();

    resumeAs($this->ion, $first, 'ion-2')
        ->assertOk()
        ->assertJsonPath('id', $first->id)
        ->assertJsonPath('joined', true)
        ->assertJsonPath('on_hold', false);

    expect(participantOf($first, $this->ion))->status->toBe(CallParticipant::JOINED)->tab->toBe('ion-2')
        ->and(participantOf($second, $this->ion)->status)->toBe(CallParticipant::ON_HOLD)
        ->and($second->fresh()->status)->toBe(Call::ACTIVE);

    Event::assertDispatched(ParticipantResumed::class);

    $this->actingAs($this->ion)->getJson(callsRoute('poll', ['call' => $first->id, 'tab' => 'ion-2', 'claim' => 1]))
        ->assertJsonPath('call.id', $first->id)
        ->assertJsonPath('on_hold.id', $second->id);

    // Not on hold any more: nothing to resume.
    resumeAs($this->ion, $first)->assertStatus(422)->assertJsonPath('message', 'This call is not on hold.');
});

it('gives up a call I am still ringing someone on when I resume the one on hold', function (): void {
    $first = talk($this->ion, $this->ana);
    holdAs($this->ion, $first)->assertOk();
    $second = ringGroup($this->ion, [$this->mihai], 'ion-1');

    resumeAs($this->ion, $first)->assertOk();

    expect($second->fresh())->status->toBe(Call::ENDED)->end_reason->toBe('cancelled');
});

it('ends a 1-on-1 call when the person left waiting hangs up, or when I end it from the hold', function (): void {
    $call = talk($this->ion, $this->ana);
    holdAs($this->ion, $call)->assertOk();

    $this->actingAs($this->ana)->postJson(callsRoute('hangup', ['call' => $call]))->assertOk();

    expect($call->fresh())->status->toBe(Call::ENDED)->end_reason->toBe('hung_up');
    $this->actingAs($this->ion)->getJson(callsRoute('poll'))->assertJsonPath('on_hold', null);
    resumeAs($this->ion, $call)->assertStatus(410);

    $again = talk($this->ion, $this->ana);
    holdAs($this->ion, $again)->assertOk();
    $this->actingAs($this->ion)->postJson(callsRoute('hangup', ['call' => $again]))->assertOk();

    expect($again->fresh()->status)->toBe(Call::ENDED)
        ->and(participantOf($again, $this->ion)->status)->toBe(CallParticipant::LEFT);
});

it('keeps a group call going for the others, and for the one left when everyone else is on hold', function (): void {
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1')->assertOk();
    answerAs($this->mihai, $call, 'mihai-1')->assertOk();

    holdAs($this->ion, $call)->assertOk();
    $this->actingAs($this->mihai)->postJson(callsRoute('hangup', ['call' => $call]))->assertOk();

    // Ana alone with Ion on hold: the call waits for him.
    expect($call->fresh()->status)->toBe(Call::ACTIVE);

    // Signals go to the people in the call, never to someone away.
    Holler::signal($call->fresh('participants'), (string) $this->ana->getKey(), 'state', ['muted' => true]);
    expect(CallSignal::query()->where('to_user_id', (string) $this->ion->getKey())->count())->toBe(0);

    expect(fn () => Holler::signal($call->fresh('participants'), (string) $this->ion->getKey(), 'state', []))->toThrow(CallNotAllowed::class);
});

it('does not count someone as busy for a call they put on hold', function (): void {
    $call = talk($this->ion, $this->ana);
    holdAs($this->ion, $call)->assertOk();

    // Ion stepped out: Mihai can ring him. Ana is still in the call: she is busy.
    $this->actingAs($this->mihai)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertCreated();
    $this->actingAs($this->dana)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertStatus(409);
});

it('keeps a hold while a window of mine polls, and lets go of someone who closed everything', function (): void {
    config()->set('holler.hold_timeout', 60);
    $call = ringGroup($this->ion, [$this->ana, $this->mihai]);
    answerAs($this->ana, $call, 'ana-1')->assertOk();
    answerAs($this->mihai, $call, 'mihai-1')->assertOk();
    holdAs($this->ion, $call)->assertOk();

    // Three minutes on hold, with Ion's page still open somewhere.
    for ($elapsed = 0; $elapsed < 180; $elapsed += 5) {
        $this->travel(5)->seconds();
        pollHolding($this->ana, $call);
        pollHolding($this->mihai, $call);
        $this->actingAs($this->ion)->getJson(callsRoute('poll'))->assertJsonPath('on_hold.id', $call->id);
    }

    expect(participantOf($call, $this->ion)->status)->toBe(CallParticipant::ON_HOLD);

    // He closes the browser: after the timeout he is let go, and the call goes on for the others.
    keepPolling($call, [$this->ana, $this->mihai], 65);

    expect(participantOf($call, $this->ion)->status)->toBe(CallParticipant::DROPPED)
        ->and($call->fresh()->status)->toBe(Call::ACTIVE);
});

it('never drops someone on hold when the timeout is off', function (): void {
    config()->set('holler.hold_timeout', 0);
    $call = talk($this->ion, $this->ana);
    holdAs($this->ion, $call)->assertOk();

    keepPolling($call, [$this->ana], 600);

    expect(participantOf($call, $this->ion)->status)->toBe(CallParticipant::ON_HOLD);
});
